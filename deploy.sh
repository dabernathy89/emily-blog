#!/bin/bash
set -e

docker --context default build --target production -t statamic-cms:latest .
docker save statamic-cms:latest | gzip | ssh dabernathy@192.168.100.151 'gunzip | docker load'
docker --context emilyblog stack deploy -c docker-stack-cms.yml statamic

# Swarm won't restart a service when the image tag (latest) hasn't changed,
# because local images have no registry digest to compare. Force a restart.
docker --context emilyblog service update --force statamic_statamic

echo "Waiting for new container to be ready..."
sleep 15

echo "Clearing Stache cache..."
docker --context emilyblog exec $(docker --context emilyblog ps -q --filter name=statamic_statamic) php please stache:clear
