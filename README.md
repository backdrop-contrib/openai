# OpenAI / ChatGPT / AI Search Integration

> **Deprecated:** This module is no longer developed. It is replaced by
> [`ai`](https://github.com/backdrop-contrib/ai) and [`ai_provider_openai`](https://github.com/backdrop-contrib/ai_provider_openai), part of the [AI](https://github.com/backdrop-contrib/ai) suite for Backdrop CMS.

The submodules map to the AI suite as follows: `openai_alt` → `ai_alt`,
`openai_ckeditor` → `ai_ckeditor`, `openai_content` → `ai_content`,
`openai_dblog` → `ai_dblog`, `openai_moderation` → `ai_moderation`, and
`openai_chatgpt`, `openai_audio`, `openai_tts` and `openai_dalle` →
`ai_explorer`. `openai_prompt` and `openai_embeddings` have no replacement
and are disabled by the upgrade.

If you have this module installed, upgrade to the replacement:

1. Add the [AI](https://github.com/backdrop-contrib/ai) module and the replacement module(s) to your site.
2. Enable **AI Upgrade UI** (`ai_upgrade`, included with AI).
3. Run the upgrade at `admin/config/ai/upgrade` (or `bee ai-upgrade`). It
   enables the replacements, copies your settings, permissions, blocks and
   stored data, and disables this module.

The Backdrop CMS OpenAI module makes it possible to interact with the
[OpenAI API](https://openai.com/) to implement features using
various API services.

The OpenAI module aims to provide a suite of modules and an API foundation
for OpenAI integration in Backdrop CMS for generating text content, images, content
analysis and more. OpenAI is the company behind artificial generational
intelligence products that powers applications like ChatGPT, GPT-3, GitHub
CoPilot, and more. Our goal is to find ways of augmenting and adding assistive
AI tech leveraging OpenAI API services in Backdrop CMS.

## Requirements

You are required to provide an OpenAI key before you can use
any of the provided services.

## Documentation

Please review and contribute to documentation for this module in our [wiki](https://github.com/backdrop-contrib/openai/wiki). 

## Installation

- Install this module using the official [Backdrop CMS instructions](https://backdropcms.org/user-guide/modules).

- Enable the core OpenAI module and one or more submodules that meet your needs.

## Included Submodules

### **openai_audio**  
Adds capability to interact with the OpenAI audio (speech-to-text) endpoints.

### **openai_chatgpt**  
Enables interaction with the Chat endpoint via the ChatGPT API.

### **openai_ckeditor**  
Provides a button for CKEditor 5 to send a prompt to OpenAI and receive generated text back.

### **openai_content**  
Adds assistive tools for different areas of the content editing process. This includes functionality to adjust the tone of the content, summarize body text, suggest taxonomy terms for nodes, and check content for [moderation violations](https://platform.openai.com/docs/guides/moderation/overview).

### **openai_dalle**  
Adds capability to interact with the OpenAI DALL·E (image generation) endpoint, supporting both the new DALL·E 3 model and DALL·E 2 model.

### **openai_devel**  
Adds GPT content generation capability to Devel Generate. This provides Devel a way of generating realistic content (not lorem ipsum) using GPT and ChatGPT models. Users can generate sample content from the Drupal UI or via Drush. This is useful for filling out your site with realistic content for client demonstrations, layout, theming, or QA.

### **openai_dblog**  
Demonstrates log analysis using OpenAI to find potential solutions or explanations for error logs. Responses from OpenAI are saved and persist for common error messages, allowing you to review them.

### **openai_prompt**  
Adds an area in the admin interface to explore OpenAI text generation capabilities and ask it (prompt) for whatever you'd like.

### **openai_embeddings**  
Analyzes nodes and generates vectors and text embeddings of your nodes, taxonomy, media, and paragraph entities from OpenAI. Responses from OpenAI are saved and could augment search, ranking, automatically suggest taxonomy terms for content, and [improve search relevancy without expensive search backends. Content personalization and recommendation](https://www.pinecone.io/) may also be possible with this approach.

### **openai_tts**  
Adds capability to interact with the OpenAI TTS (text-to-speech) endpoints.

## Issues

Bugs and feature requests should be reported in the [Issue Queue](https://github.com/backdrop-contrib/openai/issues).

## Current Maintainer

[Justin Keiser](https://github.com/keiserjb)

## Credits

- Ported to Backdrop CMS by [Justin Keiser](https://github.com/keiserjb).
- Created for Drupal by [Kevin Quillen](https://www.drupal.org/u/kevinquillen).

### Drupal Maintainers

- Kevin Quillen - [kevinquillen](https://www.drupal.org/u/kevinquillen)
- Laurence Mercer - [laurencemercer](https://www.drupal.org/u/laurencemercer)
- Raffaele Chiocca - [rafuel92](https://www.drupal.org/u/rafuel92)
- Julien Alombert - [Julien Alombert](https://www.drupal.org/u/julien-alombert)
- Scott Euser - [scott_euser](https://www.drupal.org/u/scott_euser)

## License

This project is GPL v2 software. See the LICENSE.txt file in this directory for complete text.
