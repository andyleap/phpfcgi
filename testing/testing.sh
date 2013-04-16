#!/bin/bash

spawn-fcgi -n -s /tmp/phpfcgi.sock -M 0666 -- /usr/bin/php testing.php
