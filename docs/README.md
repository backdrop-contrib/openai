# OpenAI Module Documentation

This directory contains documentation for the OpenAI module and its various provider integrations.

## Contents

- [**PROVIDERS.md**](./PROVIDERS.md): Detailed information on the pluggable provider architecture, how it works, and how to develop new provider integrations.

## Overview

The OpenAI module for Backdrop CMS has been designed to be provider-agnostic. While it started as a direct integration with OpenAI's API, it now supports a unified interface that can talk to many different AI services:

- **Anthropic (Claude)**
- **Google Gemini**
- **Groq**
- **Ollama (Local AI)**
- **OpenRouter**

Each of these is implemented as a separate submodule (e.g., `openai_anthropic`) that provides an adapter class implementing the `AIClientInterface`.

## Key Concepts

- **Provider**: A service that provides AI capabilities (e.g., Anthropic).
- **Adapter**: A PHP class that translates between the OpenAI module's internal calls and the provider's specific API.
- **Model**: A specific AI model offered by a provider (e.g., `claude-3-5-sonnet-20240620`).
- **Capability**: What a model can do (text generation, image generation, vision, embeddings, etc.).

For developers looking to extend this module, please see [PROVIDERS.md](./PROVIDERS.md).
