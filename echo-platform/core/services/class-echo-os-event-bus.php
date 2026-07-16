<?php

defined( 'ABSPATH' ) || exit;

/** Typed facade over WordPress actions for decoupled module communication. */
final class Echo_OS_Event_Bus {
    private Echo_OS_Logger $logger;

    public function __construct( Echo_OS_Logger $logger ) {
        $this->logger = $logger;
    }

    public function emit( string $event, array $payload = array() ): void {
        $event = sanitize_key( $event );
        if ( '' === $event ) {
            return;
        }
        $envelope = array(
            'event'      => $event,
            'payload'    => $payload,
            'occurred_at'=> current_time( 'mysql', true ),
            'request_id' => wp_generate_uuid4(),
        );
        $this->logger->log( 'info', 'event', $event, array( 'request_id' => $envelope['request_id'] ) );
        do_action( 'echo_os_event_bus', $event, $envelope );
        do_action( 'echo_os_event_' . $event, $envelope );
        // Preserve the pre-kernel event hook used by current modules.
        do_action( 'echo_os_event', $event, $event, $payload );
    }

    public function listen( string $event, callable $listener, int $priority = 10 ): void {
        $event = sanitize_key( $event );
        if ( '' !== $event ) {
            add_action( 'echo_os_event_' . $event, $listener, $priority, 1 );
        }
    }
}
