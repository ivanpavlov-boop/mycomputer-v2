FROM node:22-alpine AS build

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN npm run build \
    && npm prune --omit=dev \
    && npm cache clean --force

FROM node:22-alpine AS runtime

WORKDIR /app

ENV NODE_ENV=production
ENV NITRO_HOST=0.0.0.0
ENV NITRO_PORT=3000

COPY --from=build /app/package.json /app/package-lock.json ./
COPY --from=build /app/node_modules ./node_modules
COPY --from=build /app/.output ./.output

EXPOSE 3000

CMD ["node", ".output/server/index.mjs"]
