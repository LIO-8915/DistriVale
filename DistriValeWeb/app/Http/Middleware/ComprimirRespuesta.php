<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Comprime con gzip las respuestas grandes. El servidor integrado de PHP en
 * Windows (php artisan serve, el que arranca Tauri) a veces corta respuestas
 * de cientos de KB a medio envío: la vista "Todos" de Liquidación (~500 KB)
 * llegaba sin la mitad de la tabla. Comprimida pesa ~25 KB y ya no pasa.
 */
class ComprimirRespuesta
{
    private const MINIMO_BYTES = 16 * 1024;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response instanceof BinaryFileResponse
            || $response instanceof StreamedResponse
            || $response->headers->has('Content-Encoding')
            || ! str_contains((string) $request->header('Accept-Encoding'), 'gzip')
            || ! function_exists('gzencode')
            || ! $this->esTexto((string) $response->headers->get('Content-Type'))) {
            return $response;
        }

        $contenido = $response->getContent();
        if ($contenido === false || strlen($contenido) < self::MINIMO_BYTES) {
            return $response;
        }

        $response->setContent(gzencode($contenido, 6));
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($response->getContent()));
        $response->headers->set('Vary', 'Accept-Encoding', false);

        return $response;
    }

    /**
     * Solo páginas y texto. Las descargas (PDF, etc.) se mandan tal cual: ya
     * vienen comprimidas por dentro y un Content-Encoding en una descarga es
     * una variable más para el gestor de descargas de WebView2.
     */
    private function esTexto(string $tipo): bool
    {
        return $tipo === ''
            || str_starts_with($tipo, 'text/')
            || str_contains($tipo, 'json')
            || str_contains($tipo, 'javascript')
            || str_contains($tipo, 'xml');
    }
}
