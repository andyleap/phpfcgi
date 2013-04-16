phpfcgi
=======

Simple PHP FCGI server system

Check out the demo, it needs a fcgi compliant web server to connect to it at /tmp/phpfcgi.sock
Works with lighttpd

I recommend a config like

	fastcgi.server = (
		"/phpfcgi" =>
		((
			"socket" => "/tmp/phpfcgi.sock",
			"check-local" => "disable"
		))
	)

and run start-phpfcgi.sh to start up the php side, then navigate to /phpfcgi on your server.
