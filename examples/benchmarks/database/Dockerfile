ARG BASE_IMAGE=debian:bookworm-slim
FROM ${BASE_IMAGE}
ENV LC_ALL=C LANG=C TZ=UTC
RUN apt-get update \
    && apt-get install -y --no-install-recommends sysbench=1.0.20+ds-5 \
    && rm -rf /var/lib/apt/lists/*
USER 65534:65534
