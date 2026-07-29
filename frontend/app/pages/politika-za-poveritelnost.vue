<template>
  <LegalDocumentPage
    :document="privacyDocument"
    :version="legalManifest.privacy.version"
    :effective-date="legalManifest.privacy.effective_date"
    :is-draft="legalManifest.status === 'draft'"
    :canonical-path="legalManifest.privacy.route"
  />
</template>

<script setup lang="ts">
import legalManifest from '~/data/legal/legal-content-manifest.json'
import { privacyDocument } from '~/data/legal/privacy.bg'

const { locale } = useI18n()

if (locale.value !== 'bg') {
  throw createError({ statusCode: 404, statusMessage: 'Page Not Found' })
}
</script>
