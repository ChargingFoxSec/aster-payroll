FROM mcr.microsoft.com/devcontainers/php:8.3-bookworm

ARG USERNAME=vscode

RUN apt-get update \
    && export DEBIAN_FRONTEND=noninteractive \
    && apt-get install -y --no-install-recommends \
        default-mysql-client \
        redis-tools \
        ripgrep \
        build-essential \
        pkg-config \
        libssl-dev \
        libudev-dev \
        libclang-dev \
        protobuf-compiler \
        libprotobuf-dev \
        jq \
        unzip \
    && docker-php-ext-install pdo_mysql \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

USER ${USERNAME}

ENV HOME=/home/${USERNAME}
ENV PATH=${HOME}/.local/share/solana/install/active_release/bin:${HOME}/.cargo/bin:${HOME}/.avm/bin:${PATH}

# Official Solana quick install. This installs Rust, Solana CLI, and AVM/Anchor tooling.
RUN curl --proto '=https' --tlsv1.2 -sSfL https://solana-install.solana.workers.dev | bash

RUN echo 'export PATH="$HOME/.local/share/solana/install/active_release/bin:$HOME/.cargo/bin:$HOME/.avm/bin:$PATH"' >> ${HOME}/.bashrc \
    && echo 'export PATH="$HOME/.local/share/solana/install/active_release/bin:$HOME/.cargo/bin:$HOME/.avm/bin:$PATH"' >> ${HOME}/.zshrc

WORKDIR /workspaces/frontiers-hackathon
