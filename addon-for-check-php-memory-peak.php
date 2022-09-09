<?php
/*
Plugin Name: Addon for Check PHP Memory Peak
Plugin URI:
Description: An addon for Check PHP Memory Peak to send memories to Cloudwatch Metrics.
Version: 0.1
Author: PRESSMAN
Author URI:
Text Domain: addon-for-check-php-memory-peak
License: GNU GPL v2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/


if ( ! defined( 'ABSPATH' ) ) {
	die();
}

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\Credentials\Credentials;
use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;


class Send_Memory_Cloud_Watch_Logs {

	private static $instance;
	public $peak_memory, $client;
	private $sequence_token_option_name = 'wp10_cwl_sequence_token';
	private $sequence_token;
	private $error_count = 0;
	private $max_error_count = 2;

	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	function __construct() {
		add_action( 'cpmp_peak_data', [$this, 'check_memory_from_cpmp'] );
	}

	/**
	 * メモリ閾値と比較し、敷地値を超えていればcloudwatch logsに値を送信する
	 */
	function check_memory_from_cpmp( $peak_memory ) {
		$this->set_peak_memory( $peak_memory );
		if ( ! $this->is_put() ) {
			return;
		}

		// すでに別プラグイン等にAWS SDKが読み込まれている場合
		// この定数を定義することで読み込まれなくなる
		if ( ! defined( 'WP10_CWL_MEMORY_AWS_SDK_EXIST' ) || ! WP10_CWL_MEMORY_AWS_SDK_EXIST ) {
			require plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
		}

		$this->set_cloudwatch_logs_client();
		$this->put_cloudwatch_log();
	}

	/**
	 * cloudwatch logsへ送信処理を進めるかどうか判断
	 *
	 * @return boolean
	 */
	function is_put() {
		// 必要な定数が足りていない場合、送信しない
		if ( ! $this->defined_validate() ) {
			return false;
		}
		// ピークメモリがこのプラグイン用の閾値以下の場合、送信しない
		if ( $this->peak_memory < WP10_CWL_MEMORY_THRESHOLD ) {
			return false;
		}
		return true;
	}

	function set_peak_memory( $peak_memory ) {
		$this->peak_memory = $peak_memory;
	}

	/**
	 * cloudwatch logsにログを送信する
	 *
	 * @return void
	 */
	function put_cloudwatch_log() {
		$args = [
			'logGroupName' => WP10_AWS_CWL_MEMORY_LOG_GROUP_NAME,
			'logStreamName' => WP10_AWS_CWL_MEMORY_LOG_STREAM_NAME,
			'logEvents' => $this->get_log_event()
		];

		if ( $sequence_token = $this->get_sequence_token() ) {
			$args['sequenceToken'] = $sequence_token;
		}

		try {
			$result = $this->client->putLogEvents( $args );
			$this->set_sequence_token( $result['nextSequenceToken'] );
		} catch ( CloudWatchLogsException $e ) {
			if ( in_array( $e->getAwsErrorCode(), [ 'DataAlreadyAcceptedException', 'InvalidSequenceTokenException' ] ) ) {
				$this->set_sequence_token( $e->get('expectedSequenceToken') );

				// 何かしらの理由で都度エラーが発生する場合、無限ループとなるので一定回数で中断する
				$this->add_error_count();
				if ( ! $this->is_many_error() ) {
					$this->put_cloudwatch_log();
				}
			} else {
				throw $e;
			}
		}
	}

	/**
	 * プロパティに指定されたtokenを取得する
	 * プロパティ未指定の場合、optionに保存されたsequenceTokenを取得する
	 *
	 * @return string
	 */
	function get_sequence_token() {
		if ( $this->sequence_token ) {
			return $this->sequence_token;
		}
		return get_option( $this->sequence_token_option_name, '' );
	}

	/**
	 * プロパティ、optionに最新のsequenceTokenを保存する
	 *
	 * @return void
	 */
	function set_sequence_token( $sequence_token ) {
		$this->sequence_token = $sequence_token;
		update_option( $this->sequence_token_option_name, $sequence_token );
	}

	/**
	 * 本プラグインの機能を使用するための定数が全て設定されている確認する
	 *
	 * @return boolean
	 */
	function defined_validate() {
		// CloudWatch logsのロググループ名
		if ( ! defined( 'WP10_AWS_CWL_MEMORY_LOG_GROUP_NAME' ) ) {
			return false;
		}
		// CloudWatch logsのストリーム名
		if ( ! defined( 'WP10_AWS_CWL_MEMORY_LOG_STREAM_NAME' ) ) {
			return false;
		}
		// 本プラグイン用のメモリ閾値
		if ( ! defined( 'WP10_CWL_MEMORY_THRESHOLD' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * AWS SDK よりCloudWatch logsに送信するためのクライアントをオブジェクトにセット
	 *
	 * @return void
	 */
	function set_cloudwatch_logs_client() {
		$args = [
			'version' => '2014-03-28',
		];
		if ( defined( 'WP10_AWS_CWL_MEMORY_PROFILE' ) ) {
			$args['profile'] = WP10_AWS_CWL_MEMORY_PROFILE;
		}
		if ( defined( 'WP10_AWS_CWL_MEMORY_REGION' ) ) {
			$args['region'] = WP10_AWS_CWL_MEMORY_REGION;
		}
		if ( $this->is_credentials() ) {
			$args['credentials'] = $this->get_credentials();
		}

		$this->client = new CloudWatchLogsClient($args);
	}

	/**
	 * AWSの認証情報が定数で使用可能な状態か
	 *
	 * @return boolean
	 */
	function is_credentials() {
		if ( ! defined( 'WP10_AWS_CWL_MEMORY_KEY' ) ) {
			return false;
		}
		if ( ! defined( 'WP10_AWS_CWL_MEMORY_SECRET' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * AWS SDKで使用する認証情報を取得する
	 */
	function get_credentials() {
		return new Credentials( WP10_AWS_CWL_MEMORY_KEY, WP10_AWS_CWL_MEMORY_SECRET );
	}

	/**
	 * CloudWatch logsに送信するイベントを取得する
	 *
	 * @return array
	 */
	function get_log_event() {
		return [
			[
				'timestamp' => $this->get_timestamp(),
				'message' => $this->get_message(),
			]
		];
	}

	/**
	 * タイムスタンプを取得する
	 * wp_date関数が存在するWP Versionの場合はwp_dateを使用する
	 *
	 * @return int
	 */
	function get_timestamp() {
		return (int)( microtime( true ) * 1000 );
	}

	/**
	 * cloudwatch logsに送信するメッセージを取得する
	 *
	 * @return string
	 */
	function get_message() {
		$message = [
			'peak_memory' => $this->peak_memory,
			'current_user_id' => get_current_user_id(),
			'request' => $this->get_request(),
			'server' => $_SERVER
		];
		return wp_json_encode( $message );
	}

	/**
	 * メッセージに含める$_REQUEST内容を取得
	 * 将来的には$_REQUESTの内容を取得する関数をCheck PHP Memory Peakに持たせ、そこから値を取得したい
	 * 2022-05-20時点ではCheck PHP Memory Peakが公開プラグイン申請中のため、コード変更が難しいため同様のコードを本プラグインでも持つ
	 *
	 * @return array
	 */
	function get_request() {
		$request = $_REQUEST;
		$delete_things = ['pass1','pass2','pwd','password'];
		$delete_things = apply_filters( 'cpmp_delete_things_list', $delete_things );
		foreach ( $delete_things as $delete_thing ) {
			if ( isset( $request[ $delete_thing ] ) ) {
				unset( $request[ $delete_thing ] );
			}
		}
		return $request;
	}

	/**
	 * エラー回数をカウントアップする
	 * 
	 * @return void
	 */
	function add_error_count() {
		$this->error_count++;
	}

	/**
	 * 何度もエラーが発生しているかどうか
	 * 
	 * @return bool
	 */
	function is_many_error() {
		return $this->error_count >= $this->max_error_count;
	}
}

$send_memory_cloud_watch_logs = Send_Memory_Cloud_Watch_Logs::get_instance();
