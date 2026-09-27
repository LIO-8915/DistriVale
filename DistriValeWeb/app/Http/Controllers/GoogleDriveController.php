<?php

namespace App\Http\Controllers;

use App\Models\GoogleDriveToken;
use App\Services\GoogleDriveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Respaldo manual de la base de datos a Google Drive: conectar la cuenta,
 * subir un respaldo, restaurar el último respaldo, y deshacer una
 * restauración reciente si no era lo que se quería.
 *
 * No hay sincronización en tiempo real ni edición simultánea — es
 * "guardar en la nube" / "traer de la nube" a demanda, con un solo nivel de
 * deshacer para la restauración (la única de las acciones que sobreescribe
 * datos locales).
 */
class GoogleDriveController extends Controller
{
    public function __construct(private GoogleDriveService $drive) {}

    public function index()
    {
        $token = GoogleDriveToken::current();

        return view('respaldo.index', [
            'configured' => $this->drive->isConfigured(),
            'connected' => $token !== null,
            'accountEmail' => $token?->account_email,
            'lastBackupAt' => $token?->last_backup_at,
            'rollbackAvailable' => $this->preRestoreSnapshotPath() !== null,
            'rollbackMeta' => $this->preRestoreMeta(),
        ]);
    }

    public function connect(Request $request)
    {
        if (! $this->drive->isConfigured()) {
            return back()->with('error', 'Faltan las credenciales de Google Drive. Configura GOOGLE_DRIVE_CLIENT_ID y GOOGLE_DRIVE_CLIENT_SECRET en .env (ver ARQUITECTURA_TAURI.md).');
        }

        $verifier = Str::random(64);
        $state = Str::random(32);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $request->session()->put('google_drive_pkce_verifier', $verifier);
        $request->session()->put('google_drive_oauth_state', $state);

        $redirectUri = route('drive.callback');
        $authUrl = $this->drive->buildAuthUrl($redirectUri, $state, $challenge);

        // Google bloquea el login OAuth dentro de un WebView embebido (política
        // "disallowed_useragent"), así que se abre en el navegador del sistema
        // en vez de navegar la propia ventana de la app hacia esa URL.
        $this->openInSystemBrowser($authUrl);

        return back()->with('success', 'Se abrió tu navegador para autorizar el acceso a Google Drive. Vuelve a esta pantalla cuando termines.');
    }

    public function callback(Request $request)
    {
        $sessionState = $request->session()->pull('google_drive_oauth_state');
        $verifier = $request->session()->pull('google_drive_pkce_verifier');

        if ($request->get('error')) {
            return $this->callbackPage('No se autorizó el acceso ('.$request->get('error').'). Puedes cerrar esta pestaña.');
        }

        if (! $verifier || ! $sessionState || $request->get('state') !== $sessionState) {
            return $this->callbackPage('La solicitud de autorización no es válida o expiró. Cierra esta pestaña y prueba conectar de nuevo desde la app.');
        }

        try {
            $this->drive->handleCallback($request->get('code'), route('drive.callback'), $verifier);
        } catch (\Throwable $e) {
            Log::error('Google Drive OAuth callback falló', ['error' => $e->getMessage()]);

            return $this->callbackPage('Algo salió mal conectando con Google Drive: '.$e->getMessage());
        }

        return $this->callbackPage('¡Listo! Ya puedes cerrar esta pestaña y volver a DistriVale.', success: true);
    }

    public function disconnect()
    {
        $this->drive->disconnect();

        return back()->with('success', 'Se desconectó la cuenta de Google Drive.');
    }

    public function backup()
    {
        if (! GoogleDriveToken::current()) {
            return back()->with('error', 'Conecta primero una cuenta de Google Drive.');
        }

        $tmpDir = storage_path('app/tmp');
        File::ensureDirectoryExists($tmpDir);
        $tmpPath = $tmpDir.'/backup-'.uniqid().'.sqlite';

        try {
            // VACUUM INTO crea una copia consistente del archivo aunque haya
            // conexiones/lecturas en curso — nunca copiamos el .sqlite "en
            // caliente" directamente.
            DB::statement('VACUUM INTO ?', [$tmpPath]);

            $this->drive->uploadBackup($tmpPath);

            return back()->with('success', 'Base de datos respaldada en Google Drive.');
        } catch (\Throwable $e) {
            Log::error('Respaldo a Google Drive falló', ['error' => $e->getMessage()]);

            return back()->with('error', 'No se pudo respaldar en Drive: '.$e->getMessage());
        } finally {
            @unlink($tmpPath);
        }
    }

    public function restore()
    {
        if (! GoogleDriveToken::current()) {
            return back()->with('error', 'Conecta primero una cuenta de Google Drive.');
        }

        $dbPath = database_path('database.sqlite');
        $tmpDir = storage_path('app/tmp');
        File::ensureDirectoryExists($tmpDir);
        $downloadPath = $tmpDir.'/restore-'.uniqid().'.sqlite';

        try {
            // 1) Bajamos a un archivo temporal primero — si la descarga se
            //    corta a la mitad, la base local actual ni se toca.
            $this->drive->downloadBackup($downloadPath);

            // 2) Verificación mínima de integridad antes de reemplazar nada:
            //    encabezado real de SQLite + PRAGMA integrity_check.
            $this->assertValidSqliteFile($downloadPath);

            // 3) Snapshot de seguridad de la base ACTUAL (para el botón
            //    "Devolver cambios"), afuera de la carpeta database/ para que
            //    sobrevivir al propio reemplazo del archivo.
            $this->savePreRestoreSnapshot($dbPath);

            // 4) Swap atómico: nunca se sobreescribe database.sqlite en el
            //    lugar, se renombra un archivo ya completo y validado sobre él.
            DB::disconnect();
            rename($downloadPath, $dbPath);

            return back()->with('success', 'Base de datos restaurada desde Google Drive. Si no era lo que esperabas, usa "Devolver cambios".');
        } catch (\Throwable $e) {
            Log::error('Restauración desde Google Drive falló', ['error' => $e->getMessage()]);
            @unlink($downloadPath);

            return back()->with('error', 'No se pudo restaurar desde Drive: '.$e->getMessage());
        }
    }

    public function rollback()
    {
        $snapshotPath = $this->preRestoreSnapshotPath();

        if (! $snapshotPath) {
            return back()->with('error', 'No hay ningún cambio de restauración para devolver.');
        }

        try {
            $dbPath = database_path('database.sqlite');
            DB::disconnect();
            rename($snapshotPath, $dbPath);
            @unlink($this->preRestoreMetaPath());

            return back()->with('success', 'Se devolvió la base de datos al estado anterior a la última restauración.');
        } catch (\Throwable $e) {
            Log::error('Rollback de restauración falló', ['error' => $e->getMessage()]);

            return back()->with('error', 'No se pudo deshacer la restauración: '.$e->getMessage());
        }
    }

    // --- Helpers internos ---

    private function preRestoreDir(): string
    {
        return storage_path('app/pre_restore');
    }

    private function preRestoreSnapshotPath(): ?string
    {
        $path = $this->preRestoreDir().'/database.sqlite';

        return file_exists($path) ? $path : null;
    }

    private function preRestoreMetaPath(): string
    {
        return $this->preRestoreDir().'/meta.json';
    }

    private function preRestoreMeta(): ?array
    {
        $path = $this->preRestoreMetaPath();
        if (! file_exists($path)) {
            return null;
        }

        return json_decode(file_get_contents($path), true) ?: null;
    }

    /**
     * Guarda una copia consistente (VACUUM INTO) de la base ACTUAL antes de
     * pisarla con la restaurada, y la fecha en que se hizo — vive fuera de
     * database/ a propósito, así sobrevive al propio reemplazo del archivo.
     * Solo se guarda un nivel: cada restauración reemplaza el snapshot
     * anterior (deshacer es de un solo paso, no un historial completo).
     */
    private function savePreRestoreSnapshot(string $currentDbPath): void
    {
        $dir = $this->preRestoreDir();
        File::ensureDirectoryExists($dir);

        $snapshotPath = $dir.'/database.sqlite';
        @unlink($snapshotPath);
        DB::statement('VACUUM INTO ?', [$snapshotPath]);

        file_put_contents($this->preRestoreMetaPath(), json_encode([
            'restored_at' => now()->toIso8601String(),
        ]));
    }

    /**
     * Chequeo de integridad antes de reemplazar la base local: encabezado
     * real de SQLite y luego PRAGMA integrity_check contra una conexión
     * aparte y descartable, sin tocar la conexión/base de la app.
     */
    private function assertValidSqliteFile(string $path): void
    {
        $header = file_get_contents($path, false, null, 0, 16);
        if ($header === false || ! str_starts_with($header, "SQLite format 3\000")) {
            throw new \RuntimeException('El archivo descargado de Drive no es una base de datos SQLite válida.');
        }

        $pdo = new \PDO('sqlite:'.$path);
        $result = $pdo->query('PRAGMA integrity_check')->fetchColumn();
        if ($result !== 'ok') {
            throw new \RuntimeException('La base de datos descargada de Drive no pasó la verificación de integridad.');
        }
    }

    private function callbackPage(string $message, bool $success = false): \Illuminate\Contracts\View\View
    {
        return view('respaldo.callback', compact('message', 'success'));
    }

    private function openInSystemBrowser(string $url): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start "" '.escapeshellarg($url), 'r'));

            return;
        }

        // Rutas de desarrollo (Linux/macOS) no usadas en producción — la app
        // solo se empaqueta para Windows — pero evitan que esto rompa si se
        // corre `php artisan serve` en otro sistema mientras se desarrolla.
        $opener = PHP_OS_FAMILY === 'Darwin' ? 'open' : 'xdg-open';
        exec($opener.' '.escapeshellarg($url).' > /dev/null 2>&1 &');
    }
}
