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

    /**
     * Sostituisce l’intero log (es. dopo «Riprova» mantenendo solo errori ancora presenti).
     *
     * @param array<int, array{attachment_id: int, message: string, timestamp?: int}> $entries
     */
    public static function set(array $entries): void {
        $clean = [];
        foreach ($entries as $e) {
            if (!is_array($e) || empty($e['attachment_id'])) {
                continue;
            }
            $clean[] = [
                'attachment_id' => (int) $e['attachment_id'],
                'message'       => (string) ($e['message'] ?? ''),
                'timestamp'     => (int) ($e['timestamp'] ?? time()),
            ];
            if (count($clean) >= self::MAX_ENTRIES) {
                break;
            }
        }
        if ($clean === []) {
            delete_option(self::OPTION_KEY);
            return;
        }
        update_option(self::OPTION_KEY, $clean);
    }
}
