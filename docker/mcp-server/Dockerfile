# Stage 1: Build TypeScript
FROM node:26-alpine AS builder
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

# Stage 2: Production image
FROM node:26-alpine AS runner
LABEL io.modelcontextprotocol.server.name="io.github.elgorro/aiquila-mcp"
LABEL org.opencontainers.image.title="AIquila MCP Server" \
      org.opencontainers.image.description="Nextcloud MCP server — 316 tools: files, calendar, contacts, mail, Talk, Deck, photos, maps, notes" \
      org.opencontainers.image.source="https://github.com/elgorro/aiquila" \
      org.opencontainers.image.url="https://github.com/elgorro/aiquila" \
      org.opencontainers.image.licenses="MIT"
WORKDIR /app
ENV NODE_ENV=production
# poppler-utils provides pdftotext, used by mail_get_attachment for PDF text extraction
RUN apk add --no-cache poppler-utils
COPY package*.json ./
RUN npm ci --omit=dev
COPY --from=builder /app/dist ./dist
RUN mkdir -p /app/state && chown -R node:node /app
EXPOSE 3339
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
  CMD node -e "fetch('http://localhost:3339/health').then(r => process.exit(r.ok ? 0 : 1)).catch(() => process.exit(1))"
USER node
CMD ["node", "dist/index.js"]
