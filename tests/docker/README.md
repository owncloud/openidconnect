# Docker-Compose setup

## Prerequisites
- `10.254.254.254` as ip on a local (loopback) device  
On MacOSx it is possible via `sudo ifconfig lo0 alias 10.254.254.254`  
For Linux based system use `ip` (i.e. `ip addr add 10.254.254.254/32 dev lo`)
- php / composer installed in order to execute the `Makefile`

## Get started
- Issue `make install-deps` to have relevant dependencies for opendiconnect installed
- In the root folder of `openidconnect` the following commands to:
  - start `docker-compose -f tests/docker/docker-compose.yml up`
  - stop `docker-compose -f tests/docker/docker-compose.yml down -v`

- ownCloud is running at [http://10.254.254.254:8080](http://10.254.254.254:8080)

## Connecting a phoenix instance
- A locally running Phoenix instance can connect to this setup with following config.json.
It is required that phoenix is running on port 8300 - follow the [build instructions](https://github.com/owncloud/phoenix#building-phoenix) 
on how to get Phoenix started locally.

```json
{
  "server": "http://10.254.254.254:8080",
  "theme": "owncloud",
  "version": "0.1.0",
  "openIdConnect": {
    "authority": "http://10.254.254.254:8080",
    "metadataUrl": "http://10.254.254.254:8080/.well-known/openid-configuration",
    "client_id": "phoenix",
    "response_type": "code",
    "scope": "openid profile email"
  },
  "apps" : [
    "files", "pdf-viewer", "markdown-editor"
  ]
}
```
