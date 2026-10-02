# syntax=docker/dockerfile:1

# Build context = repository root (see docker-compose.yml).
# Pinned: Node.js 22 LTS (alpine), sha256:7678… — the image the clean build
# is verified against. Bump only after re-verifying the full build test.

FROM node@sha256:76789712cd1ae89a1225eac9077010d68987a423588042dac30446f502f1858c AS deps

WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --legacy-peer-deps

FROM deps AS build

ENV PATH="/app/node_modules/.bin:$PATH"
# Public build-time values — set via compose build args from the host .env.
# Never bake secrets here: only NEXT_PUBLIC_* variables (browser-visible).
ARG NEXT_PUBLIC_API_URL
ARG NEXT_PUBLIC_PLATFORM_DOMAIN
ARG ALLOW_LOCAL_ORIGINS=0
ENV NEXT_PUBLIC_API_URL=$NEXT_PUBLIC_API_URL \
    NEXT_PUBLIC_PLATFORM_DOMAIN=$NEXT_PUBLIC_PLATFORM_DOMAIN \
    ALLOW_LOCAL_ORIGINS=$ALLOW_LOCAL_ORIGINS \
    NODE_ENV=production

COPY frontend/ ./
RUN npm run build

FROM node@sha256:76789712cd1ae89a1225eac9077010d68987a423588042dac30446f502f1858c AS runtime

WORKDIR /app
ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1

COPY --from=build /app/package.json /app/package-lock.json ./
RUN npm ci --omit=dev --legacy-peer-deps

COPY --from=build /app/.next /app/.next
COPY --from=build /app/public /app/public

# Run as the unprivileged node user; keep .next writable for runtime (cache).
RUN mkdir -p /tmp/npm-cache && chown -R node:node /app /tmp/npm-cache
USER node

EXPOSE 3000
CMD ["npm", "run", "start"]