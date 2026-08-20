<?php

namespace PlugShipReceiver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GitHubUpdater {

	private $slug;
	private $pluginFile;
	private $version;
	private $githubRepo;
	private $pluginName;
	private $transientKey;

	public function __construct( $pluginFile, $githubRepo, $version ) {
		$this->pluginFile   = plugin_basename( $pluginFile );
		$this->slug         = basename( $pluginFile, '.php' );
		$this->version      = $version;
		$this->githubRepo   = $githubRepo; // e.g. 'shamim0902/plugship-receiver'
		$this->pluginName   = 'PlugShip Receiver';
		$this->transientKey = 'plugship_github_update_' . md5( $this->slug );

		$this->init();
	}

	private function init() {
		$this->maybeDeleteTransient();

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'checkUpdate' ) );
		add_action( 'delete_site_transient_update_plugins', array( $this, 'deleteTransient' ) );
		add_filter( 'plugins_api', array( $this, 'pluginInfo' ), 10, 3 );
		add_filter( 'plugin_row_meta', array( $this, 'addCheckUpdateLink' ), 10, 2 );
		remove_action( 'after_plugin_row_' . $this->pluginFile, 'wp_plugin_update_row' );
		add_action( 'after_plugin_row_' . $this->pluginFile, array( $this, 'showUpdateNotification' ), 10, 2 );
	}

	public function checkUpdate( $transientData ) {
		if ( ! is_object( $transientData ) ) {
			$transientData = new \stdClass();
		}

		if ( empty( $transientData->checked ) ) {
			return $transientData;
		}

		$release = $this->getLatestRelease();

		if ( ! $release ) {
			return $transientData;
		}

		$remoteVersion = ltrim( $release['version'], 'v' );

		if ( version_compare( $this->version, $remoteVersion, '<' ) ) {
			$transientData->response[ $this->pluginFile ] = (object) array(
				'slug'         => $this->slug,
				'plugin'       => $this->pluginFile,
				'new_version'  => $remoteVersion,
				'url'          => 'https://github.com/' . $this->githubRepo,
				'package'      => $release['download_url'],
				'icons'        => array(),
				'banners'      => array(),
				'tested'       => '',
				'requires'     => '5.8',
				'requires_php' => '7.4',
			);
		}

		$transientData->last_checked                  = time();
		$transientData->checked[ $this->pluginFile ] = $this->version;

		return $transientData;
	}

	public function addCheckUpdateLink( $links, $file ) {
		if ( $this->pluginFile !== $file || ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}

		$checkUpdateUrl = esc_url( admin_url( 'plugins.php?plugship-check-update=' . time() ) );

		$links['check_update'] = '<a style="color: #583fad;font-weight: 600;" href="' . $checkUpdateUrl . '" aria-label="' . esc_attr__( 'Check Update' ) . '">' . esc_html__( 'Check Update' ) . '</a>';

		return $links;
	}

	public function showUpdateNotification( $file, $plugin ) {
		if ( is_network_admin() || ! current_user_can( 'update_plugins' ) || $this->pluginFile !== $file ) {
			return;
		}

		remove_filter( 'pre_set_site_transient_update_plugins', array( $this, 'checkUpdate' ) );
		$updateCache = get_site_transient( 'update_plugins' );
		$updateCache = $this->checkUpdate( $updateCache );
		set_site_transient( 'update_plugins', $updateCache );
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'checkUpdate' ) );
	}

	public function pluginInfo( $data, $action, $args ) {
		if ( $action !== 'plugin_information' || ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $data;
		}

		$release = $this->getLatestRelease();

		if ( ! $release ) {
			return $data;
		}

		$remoteVersion = ltrim( $release['version'], 'v' );

		return (object) array(
			'name'          => $this->pluginName,
			'slug'          => $this->slug,
			'version'       => $remoteVersion,
			'author'        => '<a href="https://github.com/' . $this->githubRepo . '">PlugShip</a>',
			'homepage'      => 'https://github.com/' . $this->githubRepo,
			'download_link' => $release['download_url'],
			'requires'      => '5.8',
			'tested'        => '',
			'requires_php'  => '7.4',
			'last_updated'  => $release['published_at'] ?? '',
			'sections'      => array(
				'description' => 'Companion plugin for the plugship CLI. Adds a REST endpoint to receive and install plugin ZIP files.',
				'changelog'   => $release['body'] ?? 'See the <a href="https://github.com/' . $this->githubRepo . '/releases">GitHub releases</a> page.',
			),
		);
	}

	private function getLatestRelease() {
		$cached = get_option( $this->transientKey );

		if ( ! empty( $cached['timeout'] ) && current_time( 'timestamp' ) < $cached['timeout'] ) {
			return $cached['value'];
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $this->githubRepo . '/releases/latest',
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept' => 'application/vnd.github.v3+json',
				),
			)
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['tag_name'] ) ) {
			return false;
		}

		// Prefer an uploaded .zip release asset over the auto-generated zipball.
		$downloadUrl = '';
		if ( ! empty( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( substr( $asset['name'], -4 ) === '.zip' ) {
					$downloadUrl = $asset['browser_download_url'];
					break;
				}
			}
		}

		if ( ! $downloadUrl ) {
			$downloadUrl = $body['zipball_url'] ?? '';
		}

		$releaseData = array(
			'version'      => $body['tag_name'],
			'download_url' => $downloadUrl,
			'body'         => $body['body'] ?? '',
			'published_at' => $body['published_at'] ?? '',
		);

		// Cache for 12 hours.
		update_option( $this->transientKey, array(
			'timeout' => strtotime( '+12 hours', current_time( 'timestamp' ) ),
			'value'   => $releaseData,
		), 'no' );

		return $releaseData;
	}

	private function maybeDeleteTransient() {
		global $pagenow;

		if ( 'update-core.php' === $pagenow && isset( $_GET['force-check'] ) ) {
			$this->deleteTransient();
		}

		if ( isset( $_GET['plugship-check-update'] ) ) {
			if ( current_user_can( 'update_plugins' ) ) {
				$this->deleteTransient();

				remove_filter( 'pre_set_site_transient_update_plugins', array( $this, 'checkUpdate' ) );
				$updateCache = get_site_transient( 'update_plugins' );
				$updateCache = $this->checkUpdate( $updateCache );
				set_site_transient( 'update_plugins', $updateCache );
				add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'checkUpdate' ) );

				wp_redirect( admin_url( 'plugins.php?s=plugship-receiver&plugin_status=all' ) );
				exit();
			}
		}
	}

	public function deleteTransient() {
		delete_option( $this->transientKey );
	}
}
