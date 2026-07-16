<?php
$root = dirname( __DIR__ ) . '/echo-platform';
$required = array(
    'core/bootstrap.php',
    'core/contracts/interface-echo-os-module.php',
    'core/class-echo-os-kernel.php',
    'core/class-echo-os-module-registry.php',
);
$failed = false;
foreach ( $required as $file ) {
    if ( ! is_file( $root . '/' . $file ) ) {
        fwrite( STDERR, "Missing Core OS file: {$file}\n" );
        $failed = true;
    }
}
$kernel = file_get_contents( $root . '/core/class-echo-os-kernel.php' );
preg_match_all( "/^\s{12}array\( '([a-z0-9_]+)', '[^']+', array\(/m", $kernel, $matches );
$ids = $matches[1] ?? array();
$expected = array( 'catalog', 'suppliers', 'images', 'vehicles', 'operations', 'platform_ui' );
foreach ( $expected as $id ) {
    if ( 1 !== count( array_keys( $ids, $id, true ) ) ) {
        fwrite( STDERR, "Missing or duplicate module definition: {$id}\n" );
        $failed = true;
    }
}
if ( count( $ids ) !== count( array_unique( $ids ) ) ) {
    fwrite( STDERR, "Duplicate module IDs detected.\n" );
    $failed = true;
}
if ( $failed ) exit( 1 );
echo 'Core OS module validation passed (' . count( $ids ) . " modules).\n";
