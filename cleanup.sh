docker ps -a --filter "name=$(hostname)" | awk '{print $1}' | xargs -r docker rm -fv
rm -rf builds/*
