<template>
  <div>
    <LayoutBreadcrumbs :items="[{ label: 'Поръчка' }]" />
    <div v-if="cart.isUnresolved || cart.isInitialLoading" class="container-page py-8" role="status">
      <p class="mb-3 text-sm text-slate-600">Зареждаме количката…</p>
      <UiLoadingState :count="2" />
    </div>
    <div v-else-if="!cart.cart" class="container-page space-y-4 py-8">
      <UiErrorState title="Не успяхме да заредим количката" :text="cart.error?.message" />
      <UiBaseButton variant="secondary" @click="retryCart">Опитай отново</UiBaseButton>
    </div>
    <div v-else-if="cart.isConfirmedEmpty" class="container-page py-8">
      <UiEmptyState title="Количката е празна" text="Добавете продукт, преди да продължите към поръчка." />
      <NuxtLink class="mt-4 inline-flex text-sm font-semibold text-brand-700" to="/catalog">Към каталога</NuxtLink>
    </div>
    <form
      v-else
      class="container-page grid gap-6 lg:grid-cols-[1fr_360px]"
      :aria-busy="submitting"
      @submit.prevent="submit"
    >
      <div class="space-y-6">
        <section
          v-if="!cart.canCheckout"
          class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800"
        >
          <p class="font-semibold">Количката съдържа продукти, които трябва да прегледате.</p>
          <NuxtLink class="mt-2 inline-flex font-semibold text-brand-700" to="/cart">Прегледай количката</NuxtLink>
        </section>
        <section class="surface p-5">
          <h1 class="text-2xl font-bold">Данни за клиента</h1>
          <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <UiBaseInput v-model="form.first_name" placeholder="Име" required />
            <UiBaseInput v-model="form.last_name" placeholder="Фамилия" required />
            <UiBaseInput v-model="form.email" placeholder="Имейл" required @blur="captureEmail" />
            <UiBaseInput v-model="form.phone" placeholder="Телефон" required />
          </div>
        </section>

        <section class="surface p-5">
          <h2 class="font-semibold">Фактуриране</h2>
          <label class="mt-4 flex items-center gap-2 text-sm"><input v-model="isCompany" type="checkbox"> Фирма</label>
          <div v-if="isCompany" class="mt-4 grid gap-4 sm:grid-cols-2">
            <UiBaseInput v-model="form.company_name" placeholder="Име на фирма" />
            <UiBaseInput v-model="form.vat_number" placeholder="ДДС номер" />
          </div>
          <textarea v-model="form.billing_address" class="mt-4 w-full rounded-md border border-slate-300 p-3 text-sm" rows="3" placeholder="Адрес за фактуриране" required />
        </section>

        <section class="surface p-5">
          <h2 class="font-semibold">Доставка</h2>
          <div class="mt-4 grid gap-4">
            <ShippingProviderSelect v-model="form.shipping_provider" :providers="providers" />
            <DeliveryTypeSelect v-model="form.delivery_type" />

            <ShippingOfficeSearch
              v-if="form.delivery_type === 'office'"
              v-model:search="officeSearch"
              :city="form.city"
              :offices="offices"
              @update:city="form.city = $event"
              @select="selectOffice"
            />

            <ShippingAddressForm
              v-else
              :city="form.city"
              :postcode="form.postcode"
              :address="form.shipping_address"
              @update:city="form.city = $event"
              @update:postcode="form.postcode = $event"
              @update:address="form.shipping_address = $event"
            />

            <div v-if="selectedOffice" class="rounded-md bg-brand-50 p-3 text-sm text-brand-800">
              Избран офис: {{ selectedOffice.name }}, {{ selectedOffice.city }}, {{ selectedOffice.address }}
            </div>

            <ShippingPriceBox :price="shippingPrice" :estimated="estimatedDelivery" />
          </div>
        </section>

        <section class="surface p-5">
          <h2 class="font-semibold">Плащане</h2>
          <PaymentMethodSelect v-model="form.payment_method" class="mt-3" :methods="paymentMethods" />
          <BankTransferInstructions v-if="selectedPaymentMethod?.code === 'bank_transfer'" class="mt-4" :instructions="selectedPaymentMethod.instructions" />
          <LeasingInfoBox v-if="selectedPaymentMethod?.code === 'leasing'" class="mt-4" />
          <PaymentInstructionsBox v-if="selectedPaymentMethod?.code === 'card'" class="mt-4" text="Плащането с карта е placeholder и ще върне тестова страница за плащане." />
          <textarea v-model="form.notes" class="mt-4 w-full rounded-md border border-slate-300 p-3 text-sm" rows="3" placeholder="Бележки към поръчката" />
          <label class="mt-4 flex items-center gap-2 text-sm"><input v-model="form.terms" type="checkbox" required> Приемам общите условия</label>
        </section>
      </div>

      <aside class="surface h-fit p-5">
        <h2 class="font-semibold">Обобщение</h2>
        <div class="mt-4 space-y-3 text-sm">
          <div v-for="item in cart.paidItems" :key="item.id" class="flex justify-between gap-3">
            <span>{{ item.product.name }} x {{ item.quantity }}</span>
            <span>{{ Number(item.total_price).toFixed(2) }} EUR</span>
          </div>
        </div>
        <div class="mt-5 border-t pt-4 text-sm">
          <div class="flex justify-between"><span>Продукти</span><span>{{ cart.subtotal.toFixed(2) }} EUR</span></div>
          <div class="mt-2 flex justify-between"><span>Доставка</span><span>{{ shippingPrice.toFixed(2) }} EUR</span></div>
          <div class="mt-3 flex justify-between text-lg font-bold"><span>Общо</span><span>{{ (cart.subtotal + shippingPrice).toFixed(2) }} EUR</span></div>
        </div>
        <UiBaseButton class="mt-5 w-full" type="submit" :disabled="!cart.canCheckout || submitting">
          {{ submitting ? 'Изпращане…' : 'Изпрати поръчка' }}
        </UiBaseButton>
        <UiErrorState v-if="error" class="mt-4" :text="error" />
      </aside>
    </form>
  </div>
</template>

<script setup lang="ts">
import type { ShippingOffice } from '~/types/api'
import { normalizeApiError } from '~/utils/apiError'

const cart = useCartStore()
const router = useRouter()
const shipping = useShipping()
const payments = usePayments()
const analytics = useAnalytics()
const cartAnalytics = useCartAnalytics()
await cart.sync().catch(() => null)

const error = ref('')
const submitting = ref(false)
const selectedOffice = ref<ShippingOffice | null>(null)
const officeSearch = ref('')
const shippingPrice = ref(0)
const estimatedDelivery = ref('')

const form = reactive({
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
  company_name: '',
  vat_number: '',
  billing_address: '',
  shipping_address: '',
  shipping_provider: 'manual',
  shipping_method: 'address',
  delivery_type: 'address',
  office_id: null as number | null,
  city: 'Sofia',
  postcode: '',
  payment_method: 'cash_on_delivery',
  notes: '',
  terms: false,
})

const isCompany = ref(false)
const { data: providersResponse } = await useAsyncData('shipping-providers', () => shipping.providers())
const { data: paymentMethodsResponse } = await useAsyncData('payment-methods', () => payments.methods())
const providers = computed(() => providersResponse.value?.data || [])
const paymentMethods = computed(() => paymentMethodsResponse.value?.data || [])
const selectedPaymentMethod = computed(() => paymentMethods.value.find((method) => method.code === form.payment_method))
const { data: officesResponse, refresh: refreshOffices } = await useAsyncData(
  'shipping-offices',
  () => shipping.offices({ provider: form.shipping_provider, city: form.city, search: officeSearch.value }),
  { watch: [() => form.shipping_provider, () => form.city, officeSearch] },
)
const offices = computed(() => officesResponse.value?.data || [])

watch(() => [form.shipping_provider, form.delivery_type, form.city, form.postcode, form.shipping_address, form.office_id], calculateShipping, { deep: true })
watch(() => form.delivery_type, (type) => {
  form.shipping_method = type === 'office' ? 'office' : 'address'
  selectedOffice.value = null
  form.office_id = null
})

function selectOffice(office: ShippingOffice) {
  selectedOffice.value = office
  form.office_id = office.id
  form.city = office.city
  form.shipping_address = office.address
  refreshOffices()
}

async function calculateShipping() {
  try {
    const response = await shipping.calculatePrice({
      provider: form.shipping_provider,
      delivery_type: form.delivery_type,
      shipping_method: form.shipping_method,
      office_id: form.office_id,
      city: form.city,
      postcode: form.postcode,
      address: form.shipping_address,
    })
    shippingPrice.value = Number(response.data.shipping_price)
    estimatedDelivery.value = response.data.estimated_delivery
  } catch {
    shippingPrice.value = 0
    estimatedDelivery.value = ''
  }
}

async function captureEmail() {
  if (!form.email.includes('@')) return

  try {
    await cart.attachEmail(form.email)
  } catch {
    // Email capture must not block checkout.
  }
}

async function retryCart() {
  await cart.sync().catch(() => null)
}

async function submit() {
  if (!cart.cart || !cart.canCheckout || submitting.value) {
    return
  }

  error.value = ''
  submitting.value = true

  try {
    await captureEmail()

    if (!cart.cart || !cart.canCheckout) {
      error.value = 'Количката трябва да бъде прегледана преди поръчка.'

      return
    }

    const confirmedCart = cart.cart
    const operationId = cartAnalytics.createOperationId('checkout')
    const api = useCartApi()
    const response = await api.checkout(form)
    await cartAnalytics.beginCheckout(operationId, confirmedCart)
    await analytics.addPaymentInfo({ payment_method: form.payment_method, value: response.data.grand_total })
    const payment = response.data.payment_transactions?.[0]
    await cart.clear()
    await router.push({
      path: '/checkout/success',
      query: {
        order: response.data.order_number,
        total: response.data.grand_total,
        email: response.data.customer_email,
        payment: form.payment_method,
        redirect: payment?.redirect_url || undefined,
        instructions: payment?.instructions || selectedPaymentMethod.value?.instructions || undefined,
      },
    })
  } catch (failure) {
    const normalized = normalizeApiError(failure)
    error.value = normalized.message

    if (['cart_price_changed', 'cart_promotion_changed'].includes(normalized.code)) {
      await cart.sync().catch(() => null)
    }
  } finally {
    submitting.value = false
  }
}

await calculateShipping()
useSeo().page('Поръчка', 'Финализиране на поръчка.', '/checkout')
</script>
