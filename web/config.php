<?php
/* See server.php for more settings. All custom configuration should be done here, not in server.php.
 * Note: To listen on port < 1024, you must run as root.
 */
 
$config = array(
	'http' => array( // Configuration of the built in http server.
					/* http -> bindTo: This is the address you want the webclient to listen to. Example: if set to 127.0.0.1:8080,
					 *                 you'd navigate your browser to http://127.0.0.1:8080/ 
					 */
					'bindTo' => '0.0.0.0:80',
					),
					
	'irc' => array( // Irc connections settings.
					/* irc -> servers: This is a list of servers that you will allow your clients to connect to. */
					'servers' => array('tcp://irc.chatzona.org:6667'),
					
					/* irc -> bindTo: The IP used for outgoing irc connections. It should be 0.0.0.0:0 in most cases */
					'bindTo' => '0.0.0.0:0',
					'enabled' => true
					),					
				
	'ident' => array( // Identd server. It is not mandatory, but it is very useful to prevent abuses. You must be root.
					/* ident -> enabled: Set to false to disable identd */
					'enabled' => false,
					
					/* ident -> bindTo: The IP used for ident connections. Do not change the port (113) unless you know 
					 *                  what you are doing. The IP should be the same as the one in the http section.
					 */
					'bindTo' => '0.0.0.0:113',
					),

	'log_to' => STDERR,
	
);
