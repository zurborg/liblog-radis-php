php=`which php`
perl=`which perl`
composer=$(php) composer.phar
phpunit=$(php) phpunit.phar
phpdoc=$(php) vendor/phpdocumentor/phpdocumentor/bin/phpdoc
phpdocmd=$(php) vendor/evert/phpdoc-md/bin/phpdocmd
yaml2json=$(perl) -MJSON -MYAML -eprint -e'encode_json(YAML::Load(join""=><>))'
getversion=$(perl) -MYAML -eprint -e'YAML::Load(join""=><>)->{version}'
V=`$(getversion) < composer.yaml`

all: | composer.json test documentation

info:
	@echo $(php)
	@$(php) -v
	@echo $(perl)
	@$(perl) -v

documentation:
	-git rm -rf docs/
	$(phpdoc) -d src/ -t docs/ --template=xml --visibility=public
	$(phpdocmd) docs/structure.xml docs/
	git add docs/*.md
	git clean -xdf docs/

clean:
	git clean -xdf -e composer.phar -e vendor

prepare: composer.json
	curl -L https://getcomposer.org/composer.phar -z composer.phar -o composer.phar
	curl -L https://phar.phpunit.de/phpunit.phar  -z  phpunit.phar -o  phpunit.phar
	$(composer) install

composer.json: composer.yaml
	$(yaml2json) < $< > $@~
	mv $@~ $@
	-rm composer.lock
	git add $@

test:
	$(phpunit) test/*.php

archive: | clean composer.json
	$(composer) archive

release:
	git push --all
	git tag -m "Release version $V" -s v$V
	git push --tags

.PHONY: all info documentation clean prepare test archive release
