CURL=/usr/bin/curl
PHP=php
MKDIR=/bin/mkdir

build:
	$(PHP) compile.php

setup:
	@echo "Installing helper packages using composer:"
	cd helper; \
	$(CURL) -s http://getcomposer.org/installer | php; \
	$(PHP) composer.phar install

clean:
	rm -rf bin/*

clean-setup:
	rm -rf helper/vendor
	rm -rf helper/composer.phar
	rm -rf helper/composer.lock
