# Docker-Compose setup

## Prerequisites
- `10.254.254.254` as ip on a local (loopback) device  
On MacOSx it is possible via `sudo ifconfig lo0 alias 10.254.254.254`  
For Linux based system use `ip` (i.e. `ip addr add 10.254.254.254/32 dev lo`)
- php / composer installed in order to execute the `Makefile`

## Get started
- Issue `make install-deps` to have relevant dependecies for opendiconnect installed
- In the root folder of `openidconnect` the following commands to:
  - start `docker-compose -f tests/docker/docker-compose.yml up`
  - stop `docker-compose -f tests/docker/docker-compose.yml down -v`

- owncloud is running at [http://10.254.254.254:8080](http://10.254.254.254:8080)