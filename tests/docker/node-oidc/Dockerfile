FROM node:12.2-alpine
WORKDIR /oidc
RUN apk add git && git clone https://github.com/panva/node-oidc-provider.git . && yarn install
EXPOSE 3000
ADD configuration.js /oidc/example/support/configuration.js

WORKDIR /oidc/example
CMD ["node", "standalone.js"]



