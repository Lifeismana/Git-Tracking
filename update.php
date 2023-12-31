<?php
declare(strict_types=1);
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
///This file is based on the work done by xPaw on SteamTracking for SteamDB //////////////////////////////////////////////////////
///The project can be found at the following link : https://github.com/SteamDatabase/SteamTracking ///////////////////////////////
///This specific file can found at the following link : https://github.com/SteamDatabase/SteamTracking/blob/master/update.php ////
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

ini_set( 'memory_limit', '256M' ); // Some files may be big

// YES I STOLE EVERYTHING
	new Tracker( count( $argv ) === 2 ? $argv[ 1 ] : '' );

	class Tracker
	{
		private float $AppStart;
		private bool $UseCache = true;
		private string $DIR = getcwd(); //this should probably be __DIR__ when we're not in docker

		/** @var array<string, string|array<int, string>> */
		private array $ETags = [];

		/** @var array<int, string> */
		private array $Requests = [];

		/** @var array<int, array{URL: string, File: string}> */
		private array $URLsToFetch = [];

		/** @var array<int, mixed> */
		private array $Options =
		[
			CURLOPT_USERAGENT      => 'Forge track',
			CURLOPT_ENCODING       => '',
			CURLOPT_HEADER         => 1,
			CURLOPT_AUTOREFERER    => 0,
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_FOLLOWLOCATION => 0,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_SSL_VERIFYPEER => 0,
			CURLOPT_SSL_VERIFYHOST => 0,
		];

		public function __construct( string $Option )
		{
			$this->AppStart = microtime( true );

			if( $Option === 'force' )
			{
				$this->UseCache = false;
			}

			$ETagsPath  = $this->DIR . '/etags.json';

			if( $this->UseCache && file_exists( $ETagsPath ) )
			{
				$this->ETags = json_decode( file_get_contents( $ETagsPath ), true );
			}


			$this->URLsToFetch = $this->ParseUrls( );
			$KnownUrls = [];

			foreach( $this->URLsToFetch as $Url )
			{
				$KnownUrls[] = $Url[ 'URL' ];
			}

			$Tries = 5;

			do
			{
				$URLs = $this->URLsToFetch;
				$this->URLsToFetch = [];

				$this->Log( '{yellow}' . count( $URLs ) . ' urls to be fetched...' );
				$this->Fetch( $URLs );
			}
			while( !empty( $this->URLsToFetch ) && $Tries-- > 0 );

			foreach( $this->ETags as &$ETags )
			{
				if( is_array( $ETags ) )
				{
					while( count( $ETags ) > 3 )
					{
						array_shift( $ETags );
					}
				}
			}

			unset( $ETags );

			file_put_contents( $ETagsPath, json_encode( $this->ETags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );

			$this->Log( '{lightblue}Done' );
		}

		private function HandleResponse( string $File, string $Data ) : bool
		{
			// Unzip it
			if( str_ends_with( $File, '.zip' ) )
			{
				$File = $this->DIR . '/' . $File;

				file_put_contents( $File, $Data );

				return true;
			}
			// Make sure we received everything
			else if( str_ends_with( $File, '.html' ) )
			{
				if( strrpos( $Data, '</html>' ) === false )
				{
					return false;
				}

				$Data = trim( $Data );
			}

			$OriginalFile = $File;
			$File = $this->DIR . DIRECTORY_SEPARATOR . $File;

			$Folder = dirname( $File );

			if( !is_dir( $Folder ) )
			{
				$this->Log( '{lightblue}Creating ' . $Folder );

				mkdir( $Folder, 0755, true );
			}

			if( file_exists( $File ) && $Data === file_get_contents( $File ) )
			{
				return false;
			}

			file_put_contents( $File, $Data );

			system( 'npx prettier --write' . escapeshellarg( $File ) );

			return true;
		}

		/** @param array<int, array{URL: string, File: string}> $URLs */
		private function Fetch( array $URLs ) : void
		{
			$this->Requests = [];

			$Master = curl_multi_init( );

			$WindowSize = 10;

			if( $WindowSize > count( $URLs ) )
			{
				$WindowSize = count( $URLs );
			}

			for( $i = 0; $i < $WindowSize; $i++ )
			{
				$URL = array_shift( $URLs );

				$this->CreateHandle( $Master, $URL );
			}

			unset( $URL, $WindowSize, $i );

			do
			{
				while( ( $Exec = curl_multi_exec( $Master, $Running ) ) === CURLM_CALL_MULTI_PERFORM );

				if( $Exec !== CURLM_OK )
				{
					break;
				}

				while( $Done = curl_multi_info_read( $Master ) )
				{
					$Handle = $Done[ 'handle' ];
					$URL   = curl_getinfo( $Handle, CURLINFO_EFFECTIVE_URL );
					$Code  = curl_getinfo( $Handle, CURLINFO_HTTP_CODE );

					$Request = $this->Requests[ (int)$Handle ];

					if( isset( $Done[ 'error' ] ) )
					{
						$this->Log( '{yellow}cURL Error: {yellow}' . $Done[ 'error' ] . '{normal} - ' . $URL );

						$this->URLsToFetch[ ] =
						[
							'URL'  => $URL,
							'File' => $Request
						];
					}
					else if( $Code === 304 )
					{
						$this->Log( '{green}HTTP Cache  {normal} - ' . $URL );
					}
					else if( $Code !== 200 )
					{
						$this->Log( '{yellow}Error ' . $Code . '{normal}    - ' . $URL );

						if( $Code !== 404 )
						{
							$this->URLsToFetch[ ] =
							[
								'URL'  => $URL,
								'File' => $Request
							];
						}
					}
					else
					{
						$LengthExpected = curl_getinfo( $Handle, CURLINFO_CONTENT_LENGTH_DOWNLOAD );
						$LengthDownload = curl_getinfo( $Handle, CURLINFO_SIZE_DOWNLOAD );

						if( $LengthExpected !== $LengthDownload )
						{
							$this->Log( '{lightred}Wrong Length {normal}(' . $LengthDownload . ' != ' . $LengthExpected . '){normal} - ' . $URL );

							$this->URLsToFetch[ ] =
							[
								'URL'  => $URL,
								'File' => $Request
							];
						}
						else
						{
							$HandleResponse = true;
							$HeaderSize = curl_getinfo( $Handle, CURLINFO_HEADER_SIZE );
							$Data = curl_multi_getcontent( $Handle );
							$Header = substr( $Data, 0, $HeaderSize );

							if( preg_match( '/^ETag: (.+)$/im', $Header, $Test ) === 1 )
							{
								$ETag = trim( $Test[ 1 ] );

								if( !isset( $this->ETags[ $Request ] ) || !in_array( $ETag, $this->ETags[ $Request ], true ) )
								{
									$this->ETags[ $Request ][ time() ] = $ETag;
								}
								else
								{
									$HandleResponse = false;
									$this->Log( '{green}ETag Matched{normal} - ' . $URL );
								}
							}

							if( $HandleResponse )
							{
								$Data = substr( $Data, $HeaderSize );

								if( $this->HandleResponse( $Request, $Data ) === true )
								{
									$this->Log( '{lightblue}Fetched     {normal} - ' . $URL );
								}
								else
								{
									$this->Log( '{green}Not Modified{normal} - ' . $URL );
								}
							}

							unset( $Data, $Header );
						}
					}

					curl_multi_remove_handle( $Master, $Handle );

					if( !empty( $URLs ) )
					{
						$URL = array_shift( $URLs );

						$this->CreateHandle( $Master, $URL );

						$Running = true;
					}

					unset( $Request, $Handle );
				}

				if( $Running )
				{
					curl_multi_select( $Master, 5 );
				}
			}
			while( $Running );

			curl_multi_close( $Master );
		}

		/** @param array{URL: string, File: string} $URL */
		private function CreateHandle( CurlMultiHandle $Master, array $URL ) : CurlHandle
		{
			$Handle = curl_init( );
			$File  = $URL[ 'File' ];

			$Options = $this->Options;
			$Options[ CURLOPT_URL ] = $URL[ 'URL' ];

			$this->Requests[ (int)$Handle ] = $File;

			if( $this->UseCache )
			{
				// If we have an ETag saved, add If-None-Match header
				if( isset( $this->ETags[ $File ] ) )
				{
					$Options[ CURLOPT_HTTPHEADER ] =
					[
						'If-None-Match: ' . implode( ', ', $this->ETags[ $File ] ),
					];
				}
				else if( file_exists( $File ) )
				{
					$Options[ CURLOPT_HTTPHEADER ] =
					[
						'If-Modified-Since: ' . gmdate( 'D, d M Y H:i:s \G\M\T', filemtime( $File ) ),
					];
				}
			}

			curl_setopt_array( $Handle, $Options );
			curl_multi_add_handle( $Master, $Handle );

			return $Handle;
		}

		/** @return array<int, array{URL: string, File: string}> */
		private function ParseUrls() : array
		{
			$UrlsPath = $this->DIR . '/urls.txt';

			if( !file_exists( $UrlsPath ) )
			{
				$this->Log( '{lightred}Missing ' . $UrlsPath );

				exit( 1 );
			}

			$Data = file_get_contents( $UrlsPath );
			$Data = explode( "\n", $Data );
			$Urls = [];

			foreach( $Data as $Line )
			{
				$Line = trim( $Line );

				if( empty( $Line ) || $Line[ 0 ] === '/' )
				{
					continue;
				}

				if( str_contains( $Line, '@' ) )
				{
					$Line = explode( '@', $Line );
					$File = trim( $Line[ 0 ] );
					$Url = trim( $Line[ 1 ] );
					if (substr_compare($File,"/",-1,1) == 0) {
						$File .= basename($Url);
					}
				}
				else
				{
					$Url = $Line;
					$ParsedUrl = parse_url( $Url );

					if( $ParsedUrl === false || empty( $ParsedUrl[ 'host' ] ) || empty( $ParsedUrl[ 'path' ] ) )
					{
						$this->Log( $Line . ' is malformed' );
						continue;
					}

					$File = $ParsedUrl[ 'host' ] . $ParsedUrl[ 'path' ];
				}

				$Urls[] =
				[
					'URL' => $Url,
					'File' => $File,
				];
			}

			return $Urls;
		}

		private function Log( string $String ) : void
		{
			$Log  = '[';
			$Log .= number_format( microtime( true ) - $this->AppStart, 2 );
			$Log .= 's] ';
			$Log .= $String;
			$Log .= '{normal}';
			$Log .= PHP_EOL;

			$Log = str_replace(
				[
					'{normal}',
					'{green}',
					'{yellow}',
					'{lightred}',
					'{lightblue}'
				],
				[
					"\033[0m",
					"\033[0;32m",
					"\033[1;33m",
					"\033[1;31m",
					"\033[1;34m"
				],
			$Log );

			echo $Log;
		}
	}

