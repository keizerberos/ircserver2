#!/usr/bin/env php_cap
<?php
/*! MeinChat - Copyright (c) 2012, Alex Duchesne <alex@alexou.net> */
header('content-type: application/json; charset=utf-8');
//en caso de json en vez de jsonp habría que habilitar CORS:
header("access-control-allow-origin: *");

$default_config = array( /* This is default config, if you want to change something, edit config.php, not here */
	'http' => array(
					/* This is the address the client will connect to, you have to edit IP:PORT */
					'bindTo' => '0.0.0.0:80',
					
					'webpath' => 'www',
					'default' => 'index.html',
					
					'mimes' => array('js' => 'application/javascript', 'html' => 'text/html', 'txt' => 'text/plain', 'gif' => 'image/gif'),
					'default_mime' => 'text/plain',
					
					'maxClients' => 512,
					'maxPerHost' => 64,
					
					'timeout' => 10,
					'timeout_poll' => 180,

					//If you want to use ssl, specify the PEM certificate path
					'cert' => null,

					'iplock' => true,		//A session_id is locked to a specific IP
					),
	'irc' => array(
					'servers' => array('tcp://irc.chatzona.org:6667'),
					'bindTo' => '0.0.0.0:0',
					'timeout' => 300,
					'maxClients' => 64,
					),
	'ident' => array(
					'enabled' => true,
					'bindTo' => '0.0.0.0:113',
					'timeout' => 30,
					'maxClients' => 16,
					),
					
	'log_to' => STDERR,
	
);

include 'config.php';

$config = array_replace_recursive($default_config, $config);
//$config = array_merge($default_config, $config);

function log_write($event, $type = LOG_NONE) 
{
	global $config, $http_sessions, $irc_sessions, $socket_id;
	return fwrite($config['log_to'], date('[Y/m/d H:i:s]').' [H:'.str_pad(count($http_sessions), 2, '0', STR_PAD_LEFT).' I:'.str_pad(count($irc_sessions), 2, '0', STR_PAD_LEFT).(isset($socket_id) ? ' C:'.$socket_id:'').'] '.$event."\n");
}

function send_http_response($socket, $content, $content_type = 'text/plain', $code = 200) 
{
	if (!is_resource($socket) || !is_int($code) || empty($content_type))
		return false;
		
	$content_size = strlen($content);
	

	//header('content-type: application/json; charset=utf-8');
	//header("access-control-allow-origin: *");
	
	fwrite_($socket,"HTTP/1.1 {$code} OK\n".
					"Content-Type: {$content_type}\n".
					"Access-Control-Allow-Origin: *\n".
					"Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS\n".					
					"Content-Length: {$content_size}\n".
					"\n".
					$content);
	
	clean_sock_ref($socket);
}

function dec_to_hex($dec) 
{
	$h = '';
	$hex = array(0,1,2,3,4,5,6,7,8,9,'a','b','c','d','e','f');
	do {
		$h = $hex[($dec%16)].$h;
		$dec /= 16;
	} while($dec >= 1);
   
	return $h;
}

function array_value($array, $key)  //Function array dereferencing is not support in php < 5.4 :(
{
	return $array[$key];
}

function fwrite_($fp, $content) 
{
	if (fwrite($fp, $content) === false) {
		log_write('Oh oh fwrite '.array_value(error_get_last(), 'message'));
		return false;
	}
	return true;
}

function fread_($fp, $len) 
{
	$buffer = fread($fp, $len);
	return $buffer;
}

function clean_sock_ref($socket) 
{
	global $session, $sockets, $http_sessions, $irc_sessions;
	
	$key = array_search($socket, $sockets);
	
	@fclose($socket);
	
	if (isset($irc_sessions[$key]) && !empty($irc_sessions[$key]['client']) & isset($sockets[$irc_sessions[$key]['client']])) {
		clean_sock_ref($sockets[$irc_sessions[$key]['client']]);
	}
	
	unset($sockets[$key], $http_sessions[$key], $irc_sessions[$key], $session);
}

defined('LOG_NONE') or define('LOG_NONE', 0);
defined('LOG_ERR') or define('LOG_ERR', 1);
chdir(__DIR__);

$sockets = array();
$http_sessions = array();
$http_bind_pref = 'tcp://';
$ident_sessions = array();
$irc_sessions = array();
$session  = null;
$nbr_socks = array('http' => 0,'irc' => 0,'ident' => 0,'ws' => 0);

$context = stream_context_create();

if (isset($config['http']['cert'])) {
	if (file_exists($config['http']['cert'])) {
		stream_context_set_option($context, 'ssl', 'local_cert', $config['http']['cert']);
		stream_context_set_option($context, 'ssl', 'allow_self_signed', true);
		stream_context_set_option($context, 'ssl', 'verify_peer', false);
		log_write('Using cert: '.$config['http']['cert']);
		$http_bind_pref = 'tls://';
	} else {
		log_write('Cert not found: '.$config['http']['cert'], LOG_ERR);
	}
}


($sockets['server_http'] = @stream_socket_server($http_bind_pref.$config['http']['bindTo'], $errno, $errstr, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN, $context)) 
	or die("Unable to create httpd socket: $errstr ($errno)\n");

if ($config['ident']['enabled'] == true)
	($sockets['server_ident'] = @stream_socket_server('tcp://'.$config['ident']['bindTo'], $errno, $errstr)) 
		or die("Unable to create identd socket: $errstr ($errno)\nIdentd can be disable, check config.php\n");

log_write('Waiting for clients on '.$http_bind_pref.$config['http']['bindTo']);

while (true)
{
	$s_read = $sockets;
	$_null = NULL;
	if (false === ($select_changes = @stream_select($s_read, $_null, $_null, 10 /* Tuning */))) 
	{
		log_write('Failed select, this should never happen.', LOG_ERR);
	}
	elseif ($select_changes > 0) 
	{
		foreach($s_read as $socket_id => $socket) 
		{
			if (is_int($socket_id)) $socket_id = array_search($socket, $sockets); //php bug
			
			if (strncmp($socket_id, 'server_', 7) === 0) 
			{
				$type = substr($socket_id, 7);
				$sessions = $type.'_sessions';
				
				if (!isset($$sessions)) 
				{
					log_write('Unknown server type: '.$type);
					continue;
				}
				
				$socket_id = $type.'#'.dec_to_hex($nbr_socks[$type]++);
				
				if (false !== ($sockets[$socket_id] = @stream_socket_accept($socket, 2, $new_friend_name))) 
				{
					if (count($$sessions)-1 >= $config[$type]['maxClients']) 
					{
						log_write("Rejecting connection due to maxClients ({$config[$type]['maxClients']}) reached", LOG_ERR);
						clean_sock_ref($sockets[$socket_id]);
					}
					else
					{
						log_write("New client: $new_friend_name");
						
						${$sessions}[$socket_id] = array(
							'name' => $new_friend_name,
							'type' => $type,
							'timeout' => time() + $config[$type]['timeout'],
							'buffer' => '',
						);
						
						//stream_set_blocking($socket, 0);
					}
				}
			}
			elseif(!feof($socket) && is_resource($socket) && ($buffer = fread_($socket, 8192)) !== false) 
			{
				$sessions = substr($socket_id, 0, strpos($socket_id, '#')).'_sessions';
				
				if (!isset($$sessions) || !isset(${$sessions}[$socket_id])) 
				{	//This should NEVER happen
					log_write('Unknown socket type or session: '.$socket_id);
					continue;
				}
				
				$session = &${$sessions}[$socket_id];
				$session['buffer'] .= str_replace("\r", '', $buffer);

				if ($session['type'] === 'http') // $socket_id[0] === '#'
				{
					if (strpos($session['buffer'], "\n\n") !== false) 
					{
						$request = substr($session['buffer'], 0, strpos($session['buffer'], "\n"));
						$parts = explode(' ', preg_replace('#[ ]+#', ' ', $request));
						if (strcasecmp($parts[0], 'GET') === 0) 
						{
							//We only care about the last part of the url
							$file = substr($parts[1], strrpos($parts[1], '/') + 1);
							if (empty($file)) 
							{
								$file = $config['http']['default'];
							}
							$file_ext = substr($file, strrpos($file, '.') + 1);
							
							if (file_exists($config['http']['webpath'].'/'.$file)) 
							{
								log_write('GET request (found): '.$request.' resolved to '.$config['http']['webpath'].'/'.$file);
								if (isset($config['http']['mimes'][$file_ext])) 
								{
									$file_type = $config['http']['mimes'][$file_ext];
								}
								else 
								{
									$file_type = $config['http']['default_mime'];
								}
								//This server isn't meant for large files, no need to split the serving on several iteration to avoid blocking.
								send_http_response($socket, file_get_contents($config['http']['webpath'].'/'.$file), $file_type);
							} 
							else 
							{
								log_write('GET request (not found): '.$request.' resolved to '.$config['http']['webpath'].'/'.$file);
								send_http_response($socket, '404', 'text/plain', 404);
							}
						} 
						elseif (strcasecmp($parts[0], 'POST') === 0) 
						{
							log_write('POST request (found): '.$request);
							$post_query = substr($session['buffer'], strpos($session['buffer'], "\n\n") + 2);
							parse_str($post_query, $query_components);
							
							if (!isset($query_components['a'])) 
							{
								send_http_response($socket, 'I do not understand you.', 'text/plain', 400);
								break;
							}
							
							switch(strtolower($query_components['a'])) 
							{
								case 'servers':
									log_write('servidores requeridos: '. json_encode($config['irc']['servers']));
									send_http_response($socket, json_encode($config['irc']['servers']), 'text/json');
									break;
									
								case 'session':
									$session_id = 'irc#'.md5(uniqid(time(), true));
									
									if (isset($query_components['server']) && in_array($query_components['server'], $config['irc']['servers']))
									{
										$server = $query_components['server'];
									}
									elseif (count($config['irc']['servers']) > 0)
									{
										$server = $config['irc']['servers'][0];
									}
									else
									{
										send_http_response($socket, 'FAILURE');
										break;
									}
									
									if ($sock = stream_socket_client($server, $_null, $_null, 2, STREAM_CLIENT_CONNECT, stream_context_create(isset($config['irc']['bindTo']) ? array('socket' => array('bindto' => $config['irc']['bindTo'])) : array())))
									{	
										$sockname = stream_socket_get_name($sock, false);
										$name = explode($session['name'], ':');
										$sockets[$session_id] = &$sock;
										
										$irc_sessions[$session_id] = array(
											'username' => substr(sha1($name[0]), 0, 16),
											'local_port' => substr($sockname, strrpos($sockname, ':')+1),
											'server' => $server,
											'client' => '',
											'name' => $name,
											'type' => 'irc',
											'timeout' => time() + $config['irc']['timeout'],
											'buffer' => '', // content before we parse it
											'send_buffer' => '',//content waiting for a polling socket
											);
										unset($sock, $server, $sockname, $name);
										send_http_response($socket, $session_id);
									}
									else
									{
										send_http_response($socket, 'FAILURE');
									}
									break;
								
								case 'req':
									if (!isset($query_components['session_id']) || !isset($irc_sessions[$query_components['session_id']]))
									{
										send_http_response($socket, 'FAILURE');
										break;
									}
									
									if (!is_array($query_components['req']))
									{
										send_http_response($socket, 'FAILURE');
										break;
									}
									
									foreach($query_components['req'] as $message) 
									{
										fwrite_($sockets[$query_components['session_id']], $message."\n");
									}
									unset($message);
									send_http_response($socket, 'SUCCESS');
									break;

								case 'poll':
									if (!isset($query_components['session_id']) || !isset($irc_sessions[$query_components['session_id']]))
									{
										send_http_response($socket, json_encode(array('die', 0, array('No session id!'))), 'text/json');
										break;
									}
									
									$session['poller'] = true;
									$session['timeout'] = time() + $config['http']['timeout_poll'];
									
									if (isset($query_components['timeout']) && $query_components['timeout'] < $config['http']['timeout_poll'])
									{
										log_write('Adjusting timeout, client requested '.(int)$query_components['timeout'].' seconds!');
										$session['timeout'] = time() + $query_components['timeout'];
									} 
									
									// CLOSE ANY OTHER POLLING SOCKET FOR THIS SESSION...
									if (isset($irc_sessions[$query_components['session_id']]['client']) && isset($http_sessions[$irc_sessions[$query_components['session_id']]['client']])) 
									{
										log_write('Poller collision detected!');
										clean_sock_ref($sockets[$irc_sessions[$query_components['session_id']]['client']]) ;
									}
									
									$irc_sessions[$query_components['session_id']]['timeout'] = time() + $config['irc']['timeout'];
										
									if (!empty($irc_sessions[$query_components['session_id']]['buffer'])) 
									{
										if (substr($irc_sessions[$query_components['session_id']]['buffer'], -1) == "\n") //if not, the buffer is truncated, we'll wait...
										{
												send_http_response($socket, json_encode(array('irc',0, explode("\n", $irc_sessions[$query_components['session_id']]['buffer']))), 'text/json');
												$irc_sessions[$query_components['session_id']]['buffer'] = '';
												break;
										}
									}
									$irc_sessions[$query_components['session_id']]['client'] = $socket_id;
									break;
								
								case 'quit':
									break;
									
								default:
									send_http_response($socket, 'I do not understand you.', 'text/plain', 400);
							}
						} 
						else 
						{
							send_http_response($socket, 'I do not understand you.', 'text/plain', 400);
						}
					}
				}
				elseif($session['type'] === 'ident') 
				{ //Identd
					$arguments = explode(',', str_replace(' ', '', trim($ident_sessions[$socket_id]['buffer'])));
					if (count($arguments) == 2)
					{
						foreach($irc_sessions as $key => $irc_session) 
						{
							if ($irc_session['local_port'] == $arguments[0]) 
							{
								log_write('Sending ident response: Local port '.$arguments[0].' is used by '.$key);
								fwrite_($socket, $arguments[0].' , '.$arguments[1].' : USERID : UNIX : '.$irc_session['username']);
								goto ident_sent;
							}
						}
					}
					fwrite_($socket, $arguments[0].' , '.$arguments[1].' : ERROR : NO-USER');
					ident_sent:
					clean_sock_ref($socket);
				}
				else // Then it's irc
				{
					if (!isset($sockets[$session['client']]))
					{
						continue;
					}
					
					if (substr($session['buffer'], -1) == "\n")
					{
						if (($pos = strrpos($session['buffer'], "\n")) !== false)
						{
							$send_buffer = substr($session['buffer'], 0, $pos + 1);
							$session['buffer'] = substr($session['buffer'], $pos);
						}
					}
					else 
					{
						$send_buffer = $session['buffer'];
						$session['buffer'] = '';
					}
						
					if (isset($send_buffer))
					{
						send_http_response($sockets[$session['client']], json_encode(array('irc', 0, explode("\n", $send_buffer))), 'text/json');
						//clean_sock_ref($sockets[$session['client']]);
						$session['buffer'] = '';
					}
					//find an active HTTP req query in the current session. If none found, keep buffer. Also check timeout
				}
				unset($session, $socket_id);
			}
			else 
			{
				log_write("Removed socket: it was closed by remote host.");
				clean_sock_ref($socket);
			}
		}
	}
	else 
	{ //Timed loop
		//$callbacks = ['http' => ['send_http_response', [$socket, 'Request timed out', 'text/plain', 408]]];
		foreach($sockets as $socket_id => $socket) {
			if (strncasecmp($socket_id, 'server_', 7) === 0) continue;
			if (!is_resource($socket)) {
				log_write("Removed socket: it is no longer a resource.");
				clean_sock_ref($socket);
			} elseif (array_value(stream_get_meta_data($socket), 'timed_out')) {
				log_write("Removed socket: tcp timed out.");
				clean_sock_ref($socket);
			} else {
				$sessions = substr($socket_id, 0, strpos($socket_id, '#')).'_sessions';
				if (!isset($$sessions) || !isset(${$sessions}[$socket_id])) 
				{	//This should NEVER happen
					clean_sock_ref($socket);
				}
				elseif(${$sessions}[$socket_id]['timeout'] < time())
				{
					log_write('Socket closed: request timed out.');
					if (${$sessions}[$socket_id]['type'] == 'http')
						send_http_response($socket, '["",0,["timeout"]]', 'text/json', isset(${$sessions}[$socket_id]['poller']) ? 200 : 408);
					clean_sock_ref($socket);
				}
			}
		}
	}
}
