<?php

declare(strict_types=1);

namespace FP\ImgOpt\Services;

/**
 * Log delle conversioni fallite (ultimi 50 errori).
 */
final class FailedLog {

    private const OPTION_KEY = 'fp_imgopt_failed_log';
    private const MAX_ENTRIES = 50;

    /**
     * Aggiunge un errore al log.
     */
    public static function add(int $attachment_id, string $message): void {
        $log   = get_option(self::OPTION_KEY, []);
        $log   = is_array($log) ? $log : [];
        $entry = [
            'attachment_id' => $attachment_id,
            'message'       => $message,
            'timestamp'     => time(),
        ];
        array_unshift($log, $entry);
        $log = array_slice($log, 0, self::MAX_ENTRIES);
        update_option(self::OPTION_KEY, $log);
    }

    /**
     * Restituisce le ultime voci del log.
     *
     * @return array<int, array{attachment_id: int, message: string, timestamp: int}>
     */
    public static function get(): array {
        $log = get_option(self::OPTION_KEY, []);
        return is_array($log) ? $log : [];
    }

    /**
     * Svuota il log.
     */
    public static function clear(): void {
        delete_option(self::OPTION_KEY);
    }
}
