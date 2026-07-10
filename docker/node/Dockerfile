# syntax=docker/dockerfile:1
FROM node:20-alpine

WORKDIR /app

# Install deps first for better layer caching
COPY package.json package-lock.json* ./
RUN npm ci || npm install

COPY . .

EXPOSE 5173
CMD ["npm", "run", "dev", "--", "--host"]
