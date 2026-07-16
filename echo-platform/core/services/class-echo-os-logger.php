<?php

defined( 'ABSPATH' ) || exit;

/** Central, bounded logger for Echo Platform OS. */
final class Echo_OS_Logger {
    private const OPTION = 'echo_os_core_log';
    private const LIMIT  = 500;

    public function log( string $level, string $channel, string $message, array $context = array() ): void {
        $levels = array( 'debug', 'info', 'notice', 'warning', 'error', 'critical' );
        $level  = in_array( $level, $levels, true ) ? $level : 'info';
        $entry  = array(
            'time'    => current_time( 'mysql', true ),
            'level'   => $level,
            'channel' => sanitize_key( $channel ),
            'message' => sanitize_text_field( $message ),
            'context' => $this->sanitize_context( $context ),
        );

        $items   = get_option( self::OPTION, array() );
        $items   = is_array( $items ) ? $items : array();
        $items[] = $entry;
        if ( count( $items ) > self::LIMIT ) {
            $items = array_slice( $items, -self::LIMIT );
        }
        update_option( self::OPTION, $items, false );

        /** Fires after a Core OS log entry is recorded. */
        do_action( 'echo_os_logged', $entry );
    }

    public function recent( int $limit = 50 ): array {
        $items = get_option( self::OPTION, array() );
        $items = is_array( $items ) ? $items : array();
        return array_reverse( array_slice( $items, -max( 1, min( 200, $limit ) ) ) );
    }

    private function sanitize_context( array $context ): array {
        $clean = array();
        foreach ( $context as $key => $value ) {
            $key = sanitize_key( (string) $key );
            if ( is_scalar( $value ) || null === $value ) {
                $clean[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
            } elseif ( is_array( $value ) ) {
                $clean[ $key ] = $this->sanitize_context( $value );
            } else {
                $clean[ $key ] = get_debug_type( $value );
            }
        }
        return $clean;
    }
}
