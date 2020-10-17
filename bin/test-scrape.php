<?php
/**
 * Script to test the scraper.
 *
 * @package Friends
 */

// Load WordPress.
include dirname( __DIR__, 4 ) . '/wp-load.php';
include dirname( __DIR__ ) . '/lib/class-fraidyscrape.php';

$defs = json_decode( file_get_contents( dirname( __DIR__ ) . '/lib/social.json' ), true );
$f = new \Fraidyscrape\Fraidyscrape( $defs );
$tasks = $f->detect( 'https://twitter.com/f' );

$req = true;
while ( true ) {
	$req = $f->nextRequest( $tasks );
	if ( ! $req ) {
		break;
	}
	_
	if ( $req['render'] ) {
		$obj = render( $req, $tasks );
	} else {
		$res = wp_safe_remote_request(
			$req['url'],
			array_merge( $req['options'], array( 'cache' => 'no-cache' ) )
		);

		if ( is_wp_error( $res ) ) {
			var_dump( $res );
			exit;
		}

		$obj = $f->scrape( $tasks, $req, $res );

	}

	$feed = $obj['out'];
}
