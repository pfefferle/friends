<?php
namespace Fraidyscrape;
use \Sabre\Uri;

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

function varr( $vars, $x ) {
	$k = explode( ':', $x );
	$vars = array();
	foreach ( explode( ':', $x ) as $var ) {
		if ( isset( $vars[ $var ] ) ) {
			$vars = $vars[ $var ];
		} else {
			return '';
		}
	}

	return empty( $vars ) ? '' : $vars;
}

function varx( $str, $vars ) {
	if ( ! is_string( $str ) ) {
		return $str;
	}

	$str = preg_replace_callback(
		'/\${(.+)}/',
		function ( $x ) {
			$k = array_slice( $x, 2, -1 );
			$v = varx( $k, $vars );
			return varr( $vars, $v );
		},
		$str
	);

	$str = preg_replace_callback(
		'/\$([:\w]+)/',
		function ( $x ) {
			return varr( $vars, array_slice( 1 ) );
		},
		$str
	);

	return $str;
}

class Fraidyscrape {
	private $defs;
	function __construct( $defs ) {
		$this->defs = $defs;
	}

	private function normalizeUrl( $link ) {
		$url = Uri\normalize( $link );
		$protocol = strpos( $url, '://' );

		// strip the protocol
		$url = substr( $url, $protocol + 3 );

		return $url;
	}

	private function assign( $options, $additions, $vars, $mods = null, $plain_value = false ) {
		foreach ( $additions as $id => $val ) {
			$id = varx( $id, $vars );

			if ( ! $val ) {
				unset( $options[ $id ] );
				continue;
			}

			if ( ! $plain_value ) {
				$val = varx( $val, $vars );
			}

			if ( is_array( $mods ) ) {
				foreach ( $mods as $trans ) {
					if ( 'date' === $trans ) {
						if ( is_string( $val ) ) {
							if ( preg_match( '/^\d{14,}/', $val ) ) {
								$val = substr( $val, 0, 4 ) . '-' . substr( $val, 4, 2 ) . '-' . substr( $val, 6, 2 ) . ' ' . substr( $val, 8, 2 ) . ':' . substr( $val, 10, 2 ) . ':' . substr( $val, 12, 2 ) . 'Z';
							} elseif ( preg_match( '/^\w+\s+\d{1,2}[a-z]*$/', $val ) ) {
								$val = $val . ', ' . date( 'Y' );
							}
						}

						$val = new DateTime( $val );
					} elseif ( 'int' === $trans ) {
						$val = intval( $val );
					} elseif ( 'slug' === $trans ) {
						$val = '#' . urlencode( $trans );
					} elseif ( 'url' === $trans ) {
						$val = Uri\resolve( $vars['url'], $val );
					} elseif ( 'decode-uri' === $trans ) {
						$val = urldecode( $val );
					} elseif ( 'encode-uri' === $trans ) {
						$val = urlencode( $val );
					} elseif ( 'html-to-text' === $trans ) {
						$val = html_entity_decode( $val );
					} elseif ( 0 === strpos( '*', $trans ) ) {
						$val *= intval( substr( $trans, 1 ) );
					} elseif ( 'lowercase' === $trans ) {
						$val = strtolower( $val );
					} elseif ( 'uppercase' === $trans ) {
						$val = strtoupper( $val );
					}
				}
			}

			$node = &$options;
			if ( false !== strpos( $id, ':' ) ) {
				$subkeys = array_reverse( explode( ':', $id ) );
				$id = array_shift( $subkeys );
				foreach ( $subkeys as $key ) {
					if ( ! isset( $node[ $key ] )  ) {
						$node[ $key ] = array();
					}
					$node = &$node[ $key ];
				}
			}

			$node[ $id ] = $val;
		}

		return $options;
	}

	public function detect( $url ) {
		$norm = $this->normalizeUrl( $url );
		$queue = array( 'default' );
		$vars = array(
			'url' => $url,
		);

		foreach ( $this->defs as $id => $site ) {
			$site = (object) $site;
			if ( ! isset( $site->match ) ) {
				continue;
			}

			if ( ! preg_match( '#' . $site->match . '#', $norm, $match ) ) {
				continue;
			}
			if ( isset( $site->arguments ) ) {
				foreach ( $site->arguments as $i => $argument ) {
					if ( is_string( $argument ) ) {
						$vars[ $argument ] = $match[ $i ];
					} elseif ( is_object( $argument ) ) {
						$vars = $this->assign(
							$vars,
							array(
								$argument->var => $match[ $i ],
							),
							$vars,
							isset( $argument->mod ) ? $argument->mod : null,
							true
						);
					}
				}
			}

			$queue = array();
			if ( isset( $site->depends ) ) {
				$queue = $site->depends;
			}
			$queue[] = $id;
			break;
		}

		return (object) array(
			'queue' => $queue,
			'vars' => $vars,
		);
	}

	public function nextRequest( $tasks ) {
		if ( empty( $tasks->queue ) ) {
			return;
		}

		$id = array_shift( $tasks->queue );
		$req = $this->setupRequest( $tasks, $this->defs[ $id ] );
		$req['id'] = $id;

		return $req;
	}

	public function setupRequest( $tasks, $req ) {
		$options = $this->assign(
			array(),
			array(
				'url' => ! empty( $req['url'] ) ? $req['url'] : $tasks->vars['url'],
				'headers' => array(),
				'credentials' => 'omit',
			),
			$tasks->vars,
		);
		$hostname = parse_url( $options['url'], PHP_URL_HOST );
		if ( isset( $this->defs['domains'][ $hostname ] ) ) {
			$options = $this->assign( $options, $this->defs['domains'][ $hostname ], $tasks->vars );
		}

		if ( ! empty( $req['request'] ) ) {
			$options = $this->assign( $options, $req['request'], $tasks->vars );
		}

		$url = parse_url( $options['url'] );
		if ( isset( $options['query'] ) ) {
			$url['query'] = $options['query'];
			unset( $options['query'] );
		}

		return 	array(
			'url' => Uri\build( $url ),
			'options' => $options,
			'render' => ! empty( $req['render'] ) ? $req['render'] : null,
		);
	}
}
