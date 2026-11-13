# The GAM sidecar: GAM7 behind a small allow-listed HTTP service (sidecar.py).
FROM debian:trixie-20261020-slim

ARG GAM_VERSION=7.50.00

RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl xz-utils python3 \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL "https://github.com/GAM-team/GAM/releases/download/v${GAM_VERSION}/gam-${GAM_VERSION}-linux-x86_64-glibc2.39.tar.xz" \
        | tar -xJ -C /opt \
    && ln -s /opt/gam7/gam /usr/local/bin/gam \
    && useradd --system --home-dir /gam --create-home gam

COPY --chmod=0755 sidecar.py /usr/local/bin/gam-sidecar

USER gam
ENV GAMCFGDIR=/gam
CMD ["python3", "/usr/local/bin/gam-sidecar"]
