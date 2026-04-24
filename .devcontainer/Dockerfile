FROM mcr.microsoft.com/devcontainers/php:8.3-bookworm

ARG USERNAME=vscode

RUN apt-get update \
    && export DEBIAN_FRONTEND=noninteractive \
    && apt-get install -y --no-install-recommends \
        default-mysql-client \
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
    && docker-php-ext-install pdo_mysql pcntl \
    && rm -rf /var/lib/apt/lists/*

USER ${USERNAME}

ENV HOME=/home/${USERNAME}
ENV NVM_DIR=/usr/local/share/nvm
ENV PATH=${HOME}/.local/bin:${HOME}/.local/share/solana/install/active_release/bin:${HOME}/.cargo/bin:${PATH}

# Install Node/Yarn plus the minimum Rust/Solana/Anchor toolchain needed by this repo.
RUN mkdir -p "${NVM_DIR}" "${HOME}/.local/bin" \
    && curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.3/install.sh | PROFILE=/dev/null bash \
    && . "${NVM_DIR}/nvm.sh" \
    && nvm install 20 \
    && nvm alias default 20 \
    && NODE_BIN_DIR="$(dirname "$(nvm which default)")" \
    && ln -sf "${NODE_BIN_DIR}/node" "${HOME}/.local/bin/node" \
    && ln -sf "${NODE_BIN_DIR}/npm" "${HOME}/.local/bin/npm" \
    && ln -sf "${NODE_BIN_DIR}/npx" "${HOME}/.local/bin/npx" \
    && "${NODE_BIN_DIR}/corepack" enable \
    && "${NODE_BIN_DIR}/corepack" prepare yarn@1.22.22 --activate \
    && ln -sf "${NODE_BIN_DIR}/yarn" "${HOME}/.local/bin/yarn" \
    && curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y \
    && . "${HOME}/.cargo/env" \
    && sh -c "$(curl -sSfL https://release.anza.xyz/stable/install)" \
    && cargo install --force --git https://github.com/solana-foundation/anchor --tag v0.32.1 anchor-cli --locked

RUN echo 'export PATH="$HOME/.local/bin:$HOME/.local/share/solana/install/active_release/bin:$HOME/.cargo/bin:$PATH"' >> ${HOME}/.bashrc \
    && echo 'export PATH="$HOME/.local/bin:$HOME/.local/share/solana/install/active_release/bin:$HOME/.cargo/bin:$PATH"' >> ${HOME}/.zshrc

WORKDIR /workspaces/frontiers-hackathon
