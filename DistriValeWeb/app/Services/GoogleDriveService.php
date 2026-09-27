<?php

namespace App\Services;

use App\Models\GoogleDriveToken;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around the Google OAuth2 + Drive v3 REST APIs — plain HTTP
 * calls via Laravel's Http facade, no Google SDK dependency (it's a large
 * package for what amounts to four endpoints here).
 *
 * Scope is deliberately narrow: `drive.file`, which only grants access to
 * files/folders this app itself creates, never the user's whole Drive. That
 * also means Google doesn't require an app-verification review for this to
 * work outside a short testing window — appropriate for a small business's
 * own internal tool.
 */
class GoogleDriveService
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';
    private const DRIVE_FILES_URL = 'https://www.googleapis.com/drive/v3/files';
    private const DRIVE_UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';

    private const SCOPES = 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/userinfo.email';

    private const APP_FOLDER_NAME = 'DistriVale - Respaldos';
    private const BACKUP_FILE_NAME = 'database.sqlite';

    public function isConfigured(): bool
    {
        return filled(config('services.google_drive.client_id'))
            && filled(config('services.google_drive.client_secret'));
    }

    public function isConnected(): bool
    {
        return GoogleDriveToken::current() !== null;
    }

    public function buildAuthUrl(string $redirectUri, string $state, string $codeChallenge): string
    {
        $params = [
            'client_id' => config('services.google_drive.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            // Fuerza a Google a devolver siempre un refresh_token, incluso si
            // esta cuenta ya había autorizado la app antes (si no, un
            // segundo "Conectar" podría no traer refresh_token de vuelta).
            'prompt' => 'consent',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        return self::AUTH_URL.'?'.http_build_query($params);
    }

    /**
     * Intercambia el código de autorización por tokens, guarda todo
     * (encriptado) en la fila única de google_drive_tokens.
     */
    public function handleCallback(string $code, string $redirectUri, string $codeVerifier): GoogleDriveToken
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.google_drive.client_id'),
            'client_secret' => config('services.google_drive.client_secret'),
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ])->throw();

        $data = $response->json();

        if (empty($data['refresh_token'])) {
            throw new RuntimeException(
                'Google no devolvió un refresh_token. Probablemente esta cuenta ya había '.
                'autorizado la app antes de un modo que no lo pide de nuevo; revoca el acceso '.
                'desde myaccount.google.com/permissions e intenta conectar otra vez.'
            );
        }

        $email = Http::withToken($data['access_token'])
            ->get(self::USERINFO_URL)
            ->json('email');

        // Solo una cuenta conectada a la vez: reemplaza cualquier fila previa.
        GoogleDriveToken::query()->delete();

        return GoogleDriveToken::create([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_at' => now()->addSeconds($data['expires_in']),
            'account_email' => $email,
        ]);
    }

    public function disconnect(): void
    {
        $token = GoogleDriveToken::current();
        if (! $token) {
            return;
        }

        // Best-effort: si falla la revocación remota igual borramos la fila
        // local, que es lo que de verdad le importa al usuario ("desconectar").
        try {
            Http::asForm()->post(self::REVOKE_URL, ['token' => $token->refresh_token]);
        } catch (\Throwable) {
            // ignorado a propósito
        }

        $token->delete();
    }

    /**
     * Access token vigente, renovándolo con el refresh_token si ya venció
     * (o está por vencer en menos de un minuto).
     */
    private function getValidAccessToken(): string
    {
        $token = GoogleDriveToken::current();
        if (! $token) {
            throw new RuntimeException('No hay una cuenta de Google Drive conectada.');
        }

        if (! $token->isExpired()) {
            return $token->access_token;
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.google_drive.client_id'),
            'client_secret' => config('services.google_drive.client_secret'),
            'refresh_token' => $token->refresh_token,
            'grant_type' => 'refresh_token',
        ])->throw();

        $data = $response->json();

        $token->update([
            'access_token' => $data['access_token'],
            'expires_at' => now()->addSeconds($data['expires_in']),
        ]);

        return $data['access_token'];
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->getValidAccessToken())->timeout(30);
    }

    /**
     * Busca la carpeta de respaldos de la app (y la cachea en el token); si
     * no existe (primera vez, o el usuario la borró manualmente), la crea.
     */
    private function ensureAppFolder(): string
    {
        $token = GoogleDriveToken::current();

        if ($token->folder_id) {
            // Confirmamos que sigue existiendo (drive.file no ve el resto
            // del Drive, así que un id cacheado inválido daría 404, no un
            // resultado vacío de búsqueda).
            $check = $this->http()->get(self::DRIVE_FILES_URL.'/'.$token->folder_id, [
                'fields' => 'id,trashed',
            ]);
            if ($check->ok() && ! $check->json('trashed')) {
                return $token->folder_id;
            }
        }

        $query = sprintf(
            "name='%s' and mimeType='application/vnd.google-apps.folder' and trashed=false",
            self::APP_FOLDER_NAME
        );
        $found = $this->http()->get(self::DRIVE_FILES_URL, [
            'q' => $query,
            'fields' => 'files(id,name)',
            'spaces' => 'drive',
        ])->throw()->json('files');

        if (! empty($found)) {
            $folderId = $found[0]['id'];
        } else {
            $folderId = $this->http()->post(self::DRIVE_FILES_URL, [
                'name' => self::APP_FOLDER_NAME,
                'mimeType' => 'application/vnd.google-apps.folder',
            ])->throw()->json('id');
        }

        $token->update(['folder_id' => $folderId]);

        return $folderId;
    }

    private function findBackupFileId(string $folderId): ?string
    {
        $query = sprintf(
            "name='%s' and '%s' in parents and trashed=false",
            self::BACKUP_FILE_NAME,
            $folderId
        );
        $found = $this->http()->get(self::DRIVE_FILES_URL, [
            'q' => $query,
            'fields' => 'files(id)',
            'spaces' => 'drive',
        ])->throw()->json('files');

        return $found[0]['id'] ?? null;
    }

    /**
     * Sube $localFilePath como el respaldo de la app en Drive. Si ya existe
     * uno, se actualiza el mismo archivo (no se crea uno nuevo) — así Drive
     * conserva el historial de versiones anteriores automáticamente en vez
     * de acumular copias sueltas con nombres distintos.
     */
    public function uploadBackup(string $localFilePath): void
    {
        $folderId = $this->ensureAppFolder();
        $existingId = $this->findBackupFileId($folderId);
        $contents = file_get_contents($localFilePath);

        if ($existingId) {
            $this->http()
                ->withBody($contents, 'application/x-sqlite3')
                ->patch(self::DRIVE_UPLOAD_URL.'/'.$existingId.'?uploadType=media')
                ->throw();
            $fileId = $existingId;
        } else {
            $metadata = json_encode(['name' => self::BACKUP_FILE_NAME, 'parents' => [$folderId]]);
            $boundary = 'DistriVale'.bin2hex(random_bytes(8));
            $body = "--{$boundary}\r\n".
                "Content-Type: application/json; charset=UTF-8\r\n\r\n{$metadata}\r\n".
                "--{$boundary}\r\n".
                "Content-Type: application/x-sqlite3\r\n\r\n".$contents."\r\n".
                "--{$boundary}--";

            $fileId = $this->http()
                ->withBody($body, "multipart/related; boundary={$boundary}")
                ->post(self::DRIVE_UPLOAD_URL.'?uploadType=multipart')
                ->throw()
                ->json('id');
        }

        GoogleDriveToken::current()->update([
            'backup_file_id' => $fileId,
            'last_backup_at' => now(),
        ]);
    }

    /**
     * Descarga el respaldo actual de Drive hacia $destPath. Lanza excepción
     * si no hay ningún respaldo subido todavía.
     */
    public function downloadBackup(string $destPath): void
    {
        $folderId = $this->ensureAppFolder();
        $fileId = $this->findBackupFileId($folderId);

        if (! $fileId) {
            throw new RuntimeException('Todavía no hay ningún respaldo subido a Drive.');
        }

        $response = $this->http()
            ->withOptions(['sink' => $destPath])
            ->get(self::DRIVE_FILES_URL.'/'.$fileId, ['alt' => 'media']);

        if ($response->failed()) {
            @unlink($destPath);
            throw new RequestException($response);
        }
    }
}
