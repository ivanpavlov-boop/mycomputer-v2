<template>
  <LegalDocumentPage
    :document="termsDocument"
    :version="legalManifest.terms.version"
    :effective-date="legalManifest.terms.effective_date"
    :canonical-path="legalManifest.terms.route"
  />
</template>

<script setup lang="ts">
import legalManifest from '~/data/legal/legal-content-manifest.json'
import { termsDocument } from '~/data/legal/terms.bg'

const { locale } = useI18n()

if (locale.value !== 'bg') {
  throw createError({ statusCode: 404, statusMessage: 'Page Not Found' })
}
</script>
