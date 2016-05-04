phpdoc=vendor/phpdocumentor/phpdocumentor/bin/phpdoc
phpdocmd=vendor/evert/phpdoc-md/bin/phpdocmd
yaml2json=/usr/bin/perl -MJSON -MYAML -eprint -e'encode_json(YAML::Load(join""=><>))'
composer=./composer.phar
getversion=perl -MYAML -eprint -e'YAML::Load(join""=><>)->{version}'
V=`$(getversion) < composer.yaml`

all: | composer.json documentation

documentation:
	-git rm -rf docs/
	$(phpdoc) -d src/ -t docs/ --template=xml --visibility=public
	$(phpdocmd) docs/structure.xml docs/
	git add docs/*.md
	git clean -xdf docs/

clean:
	git clean -xdf -e composer.phar -e vendor

prepare:
	-rm $(composer)*
	wget https://getcomposer.org/composer.phar -O $(composer)
	chmod +x $(composer)
	-rm -rf vendor
	$(composer) install

composer.json: composer.yaml
	$(yaml2json) < $< > $@
	git add $@

archive: | clean composer.json
	$(composer) archive

release:
	git push --all
	git tag -m "Release version $V" -s v$V
	git push --tags
