#!/bin/bash
set -e

docker --context emilyblog service update --secret-rm env_file statamic_statamic
docker --context emilyblog secret rm env_file
docker --context emilyblog secret create env_file .env.production
docker --context emilyblog stack deploy -c docker-stack-cms.yml statamic
