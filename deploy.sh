#!/bin/bash
set -e

docker build --target production -t statamic-cms:latest .
docker save statamic-cms:latest | gzip | ssh dabernathy@192.168.100.151 'gunzip | docker load'
docker --context emilyblog stack deploy -c docker-stack-cms.yml statamic
