FROM golang:1.23-alpine AS build

WORKDIR /src

COPY go.mod go.sum ./
RUN go mod download

COPY . .
RUN CGO_ENABLED=0 GOOS=linux go build -trimpath -ldflags="-s -w" -o /out/telemetry-ingester ./cmd/telemetry-ingester

FROM alpine:3.20

RUN addgroup -S app && adduser -S app -G app

USER app
COPY --from=build /out/telemetry-ingester /usr/local/bin/telemetry-ingester

ENTRYPOINT ["telemetry-ingester"]
