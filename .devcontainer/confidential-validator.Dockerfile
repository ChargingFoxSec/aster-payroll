FROM mcr.microsoft.com/devcontainers/php:8.3-bookworm

ARG USERNAME=vscode
ARG AGAVE_VERSION=v3.1.12

RUN apt-get update \
    && export DEBIAN_FRONTEND=noninteractive \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        jq \
        ripgrep \
        socat \
        unzip \
        procps \
        iputils-ping \
        build-essential \
        cmake \
        git \
        pkg-config \
        libssl-dev \
        libudev-dev \
        libclang-dev \
        zlib1g-dev \
        protobuf-compiler \
        libprotobuf-dev \
    && rm -rf /var/lib/apt/lists/*

USER ${USERNAME}

ENV HOME=/home/${USERNAME}
ENV CARGO_HOME=${HOME}/.cargo
ENV RUSTUP_HOME=${HOME}/.rustup
ENV AGAVE_INSTALL_DIR=${HOME}/.local/share/agave-source-build
ENV PATH=${AGAVE_INSTALL_DIR}/bin:${CARGO_HOME}/bin:${PATH}
ENV CARGO_NET_GIT_FETCH_WITH_CLI=true

RUN curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --profile minimal \
    && mkdir -p "${HOME}/src" "${AGAVE_INSTALL_DIR}/bin" \
    && curl --proto '=https' --tlsv1.2 -fsSL "https://github.com/anza-xyz/agave/archive/refs/tags/${AGAVE_VERSION}.tar.gz" -o /tmp/agave.tar.gz \
    && tar -xzf /tmp/agave.tar.gz -C "${HOME}/src" \
    && AGAVE_SOURCE_DIR="$(find "${HOME}/src" -maxdepth 1 -type d -name 'agave-*' | head -n 1)" \
    && test -n "${AGAVE_SOURCE_DIR}" \
    && cd "${AGAVE_SOURCE_DIR}" \
    && ./cargo build --release --locked \
        --bin solana \
        --bin solana-faucet \
        --bin solana-keygen \
        --bin solana-test-validator \
    && cp -v target/release/solana "${AGAVE_INSTALL_DIR}/bin/" \
    && cp -v target/release/solana-faucet "${AGAVE_INSTALL_DIR}/bin/" \
    && cp -v target/release/solana-keygen "${AGAVE_INSTALL_DIR}/bin/" \
    && cp -v target/release/solana-test-validator "${AGAVE_INSTALL_DIR}/bin/" \
    && rm -rf /tmp/agave.tar.gz "${HOME}/src"

WORKDIR /workspaces/frontiers-hackathon
