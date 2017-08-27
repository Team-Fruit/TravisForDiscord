<?php
require_once 't4d_secret.php';
class Util {
	const VERSION = "v1.0";
	const DEBUG = false;
	public static function secondsToText(/*int*/ $seconds)/*: string*/ {
		// if you dont need php5 support, just remove the is_int check and make the input argument type int.
		if (! \is_int ( $seconds )) {
			throw new InvalidArgumentException ( 'Argument 1 passed to secondsToText() must be of the type int, ' . \gettype ( $seconds ) . ' given' );
		}
		$dtF = new DateTime ( '@0' );
		$dtT = new DateTime ( "@$seconds" );
		$ret = '';
		if ($seconds === 0) {
			// special case
			return '0 seconds';
		}
		$diff = $dtF->diff ( $dtT );
		foreach ( array (
				'y' => 'year',
				'm' => 'month',
				'd' => 'day',
				'h' => 'hour',
				'i' => 'minute',
				's' => 'second'
		) as $time => $timename ) {
			if ($diff->$time !== 0) {
				$ret .= $diff->$time . ' ' . $timename;
				if ($diff->$time !== 1 && $diff->$time !== - 1)
					$ret .= 's';
				$ret .= ' ';
			}
		}
		return substr ( $ret, 0, - 1 );
	}
}
class Response {
	public $result_code = 200;
	public $result_data;
	public $result_data_t4d;
	public function __construct() {
		$this->result_data_t4d = array ();
		$this->result_data = array (
				"t4d" => &$this->result_data_t4d
		);
	}
	public function echoResult() {
		http_response_code ( $this->result_code );
		header ( "Content-type: application/json; charset=utf-8" );
		echo $result_json = json_encode ( $this->result_data );
		if (Util::DEBUG) {
			$dir = './logs/';
			if (! file_exists ( $dir ))
				mkdir ( $dir, 0777 );
			$name = $dir . 'log_' . date ( 'Y.m.d_H.i.s' ) . '.json';
			file_put_contents ( $name, $result_json );
		}
	}
}
class T4D {
	private $response;
	private $channelID;
	public function __construct($channelID) {
		$this->response = new Response ();
		$this->channelID = $channelID;
	}
	public function run() {
		if (! isset ( $this->channelID ))
			throw new InvalidQueryException ( "Invalid channel ID" );

		$channelID = $this->channelID;
		if (Util::DEBUG)
			$this->response->result_data_t4d += array (
					"channel_id" => $channelID
			);
		$travisdata = $this->recieveTravisData ();
		$discorddata = $this->travisDataToDiscordData ( $travisdata );
		if (Util::DEBUG)
			$this->response->result_data_t4d += array (
					"send_data" => $discorddata
			);
		$this->sendDiscordData ( $channelID, $discorddata );
	}
	private function recieveTravisData() {
		if (! array_key_exists ( "payload", $_POST ))
			throw new InvalidQueryException ( "Missing payload" );

		$payload = $_POST ["payload"];
		$data = json_decode ( $payload );

		return $data;
	}
	private function sendDiscordData($channelID, $discorddata) {
		$url = "https://discordapp.com/api/v5/channels/" . $channelID . "/messages";

		$header = [
				'Content-Type: application/json; charset=utf-8',
				'Authorization: Bot ' . T4D_TOKEN,
				'User-Agent: TravisForDiscord (' . T4D_SERVER . ', ' . Util::VERSION . ')'
		];

		$curl = curl_init ();

		curl_setopt ( $curl, CURLOPT_URL, $url );
		curl_setopt ( $curl, CURLOPT_CUSTOMREQUEST, 'POST' );
		curl_setopt ( $curl, CURLOPT_POSTFIELDS, json_encode ( $discorddata ) );
		curl_setopt ( $curl, CURLOPT_HTTPHEADER, $header );
		curl_setopt ( $curl, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt ( $curl, CURLOPT_RETURNTRANSFER, true );
		curl_setopt ( $curl, CURLOPT_HEADER, true );

		$response = curl_exec ( $curl );

		$header_size = curl_getinfo ( $curl, CURLINFO_HEADER_SIZE );
		$status_code = curl_getinfo ( $curl, CURLINFO_HTTP_CODE );
		// $header = substr($response, 0, $header_size);
		$body = substr ( $response, $header_size );
		$result = json_decode ( $body, true );

		curl_close ( $curl );

		$this->response->result_code = $status_code;
		$this->response->result_data += $result;
	}
	private function travisDataToDiscordData($travisdata) {
		$data = array (
				"embed" => array (
						"author" => array (
								"name" => $travisdata->author_name,
								"icon_url" => "https://secure.gravatar.com/avatar/" . md5 ( $travisdata->author_name )
						),
						"title" => "[" . $travisdata->repository->name . ":" . $travisdata->branch . "] Build #" . $travisdata->number,
						"description" => "🏳 " . ($travisdata->result == 0 ? "✓" : "✘") . " " . $travisdata->result_message . "\n⏱ " . Util::secondsToText ( $travisdata->duration ) . ".",
						"url" => $travisdata->build_url,
						"color" => 16052399,
						"fields" => array (
								array (
										"name" => "Changes",
										"value" => "[`" . substr ( $travisdata->commit, 0, 6 ) . "`](https://github.com/" . $travisdata->repository->owner_name . "/" . $travisdata->repository->name . "/commit/" . $travisdata->commit . ") " . explode ( "\n", $travisdata->message ) [0] . " - " . $travisdata->author_name . "\n[→ View Changeset](" . $travisdata->compare_url . ")"
								)
						)
				)
		);
		return $data;
	}
	public function launch() {
		try {
			$this->run ();
		} catch ( InvalidQueryException $e ) {
			$this->response->result_code = 400;
			$this->response->result_data_t4d += array (
					"message" => $e->getMessage ()
			);
		} catch ( RuntimeException $e ) {
			$this->response->result_code = 500;
			$this->response->result_data_t4d += array (
					"message" => $e->getMessage ()
			);
		}
		$this->response->echoResult ();
	}
}
class InvalidQueryException extends RuntimeException {
}
class Main {
	public function launch() {
		try {
			$channel = null;
			if (array_key_exists ( "PATH_INFO", $_SERVER ) && preg_match ( "/^\/?channels\//", $_SERVER ["PATH_INFO"] )) {
				if (preg_match ( "/^\/?channels\/([0-9]+)/", $_SERVER ["PATH_INFO"], $mts ))
					$channel = $mts [1];
				elseif (array_key_exists ( "channels", $_GET ) && $matchs = $_GET ["channels"])
					$channel = $matchs;
			} elseif (array_key_exists ( "q", $_GET ) && preg_match ( "/^\/?channels\//", $_GET ["q"] )) {
				if (preg_match ( "/^\/?channels\/([0-9]+)/", $_GET ["q"], $mts ))
					$channel = $mts [1];
				elseif (array_key_exists ( "channels", $_GET ) && $matchs = $_GET ["channels"])
					$channel = $matchs;
			} elseif (Util::DEBUG && array_key_exists ( "q", $_GET ) && "logs" == $_GET ["q"]) {
				if ($dir = opendir ( "logs/" )) {
					header ( "Content-type: text/plain; charset=utf-8" );
					while ( ($file = readdir ( $dir )) !== false ) {
						if ($file != "." && $file != "..") {
							echo "$file\n";
						}
					}
					closedir ( $dir );
					return;
				}
			} else {
				echo 'This address is not meant to be accessed by a web browser. Please read the readme on <a href="https://team-fruit.github.io/t4d/">GitHub</a>';
				return;
			}

			(new T4D ( $channel ))->launch ();
		} catch ( InvalidQueryException $e ) {
		} catch ( RuntimeException $e ) {
		}
	}
	public static function bootstrap() {
		(new Main ())->launch ();
	}
}

Main::bootstrap ();