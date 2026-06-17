export interface ApiCollection<T> {
  data: T[]
  links?: Record<string, unknown>
  meta?: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export interface Category {
  id: number
  name: string
  slug: string
  description?: string | null
  image?: string | null
  icon?: string | null
  sort_order?: number
  meta_title?: string | null
  meta_description?: string | null
  children?: Category[]
}

export interface Brand {
  id: number
  name: string
  slug: string
  logo_path?: string | null
  description?: string | null
  meta_title?: string | null
  meta_description?: string | null
}

export interface ProductImage {
  path: string
  alt_text?: string | null
  sort_order?: number
  is_primary?: boolean
}

export interface ProductAvailability {
  code: string
  name: string
  color?: string | null
  icon?: string | null
  badge_style?: 'solid' | 'outline' | 'soft' | string | null
  allow_purchase: boolean
  show_stock_quantity?: boolean
  message?: string | null
  expected_date?: string | null
  supplier_lead_time_days?: number | null
}

export interface ProductCard {
  id: number
  sku: string
  ean?: string | null
  mpn?: string | null
  name: string
  slug: string
  short_description?: string | null
  currency?: string
  price: string | number
  promo_price?: string | number | null
  quantity: number
  stock_status: string
  availability?: ProductAvailability | null
  warranty_months?: number | null
  featured: boolean
  new_product: boolean
  bestseller: boolean
  average_rating?: number
  reviews_count?: number
  brand?: Brand
  category?: Category
  primary_image?: ProductImage | null
}

export interface ProductDetail extends ProductCard {
  description?: string | null
  weight?: string | number | null
  promo_start?: string | null
  promo_end?: string | null
  images: ProductImage[]
  attributes: Array<{
    group: { name: string; slug: string }
    attributes: Array<{
      attribute: { name: string; slug: string; unit?: string | null; is_filterable: boolean }
      value: { value: string; slug?: string | null }
    }>
  }>
  related_products: ProductCard[]
  accessory_products: ProductCard[]
  rating_distribution?: Record<number, number>
  verified_reviews_count?: number
  seo: {
    meta_title?: string | null
    meta_description?: string | null
    meta_keywords?: string | null
  }
  structured_data: Record<string, unknown>
}

export interface HomeResponse {
  hero_banners: Array<{ title: string; subtitle?: string; image?: string | null; url?: string }>
  featured_categories: Category[]
  featured_products: ProductCard[]
  new_products: ProductCard[]
  bestsellers: ProductCard[]
  promotional_products: ProductCard[]
  latest_articles: unknown[]
}

export interface CategoryFilters {
  brands: Array<{ id: number; name: string; slug: string; products_count: number }>
  price_range: { min: string | number | null; max: string | number | null }
  stock_statuses: Record<string, number>
  attributes: Array<{
    id: number
    name: string
    slug: string
    unit?: string | null
    group?: string | null
    values: Array<{ id: number; value: string; slug: string; products_count: number }>
  }>
}

export interface CartItem {
  id: number
  product_id: number
  quantity: number
  unit_price: string | number
  total_price: string | number
  product: ProductCard
}

export interface ProductBundleLine {
  id: number
  component_group?: string | null
  is_required?: boolean
  quantity?: number
  min_quantity?: number | null
  max_quantity?: number | null
  product?: ProductCard | null
}

export interface ProductBundleOption {
  id: number
  component_group: string
  price_adjustment?: string | number | null
  is_default: boolean
  product?: ProductCard | null
}

export interface ProductBundle {
  id: number
  name: string
  slug: string
  type: string
  pricing_type: string
  short_description?: string | null
  description?: string | null
  image_path?: string | null
  original_price: string | number
  price: string | number
  savings: string | number
  items?: ProductBundleLine[]
  options?: ProductBundleOption[]
  seo?: {
    meta_title?: string | null
    meta_description?: string | null
  }
}

export interface CartBundleItem {
  id: number
  bundle_id: number
  bundle_name: string
  selected_items: Array<Record<string, unknown>>
  quantity: number
  unit_price: string | number
  total_price: string | number
  original_price: string | number
  savings: string | number
}

export interface CartResponse {
  id: number
  cart_session_id: string
  status: string
  items: CartItem[]
  bundle_items?: CartBundleItem[]
  items_count: number
  subtotal: string | number
}

export interface OrderResponse {
  id: number
  order_number: string
  customer_email: string
  customer_phone: string
  customer_name: string
  subtotal: string | number
  shipping_price: string | number
  discount_total: string | number
  grand_total: string | number
  payment_method: string
  payment_status: string
  shipping_method: string
  shipping_status: string
  status: string
  items: Array<{ product_name: string; sku: string; quantity: number; unit_price: string | number; total_price: string | number }>
  payment_transactions?: PaymentTransaction[]
}

export interface PaymentMethod {
  id: number
  name: string
  code: string
  type: string
  description?: string | null
  instructions?: string | null
  sort_order: number
}

export interface PaymentTransaction {
  id: number
  transaction_id?: string | null
  amount: string | number
  currency: string
  status: string
  redirect_url?: string | null
  instructions?: string | null
  payment_method?: PaymentMethod
}

export interface ShippingProvider {
  id: number
  name: string
  code: string
  status: string
}

export interface ShippingMethod {
  id: number
  provider: ShippingProvider
  name: string
  code: string
  type: string
  price: string | number
  free_shipping_threshold?: string | number | null
}

export interface ShippingOffice {
  id: number
  provider: ShippingProvider
  office_id: string
  name: string
  city: string
  postcode?: string | null
  address: string
  phone?: string | null
}

export interface ShippingCalculation {
  shipping_price: string | number
  estimated_delivery: string
  provider: string
  method: string
}

export interface WishlistItem {
  id: number
  product_id: number
  product: ProductCard | null
}

export interface Wishlist {
  id: number
  name: string
  is_default: boolean
  items_count?: number
  items?: WishlistItem[]
}

export interface CompareItem {
  id: number
  product_id: number
  sort_order: number
  product: ProductCard | null
}

export interface CompareList {
  id: number
  session_id?: string | null
  name?: string | null
  max_products: number
  items_count: number
  items: CompareItem[]
}

export interface ProductReview {
  id: number
  customer_name: string
  rating: number
  title?: string | null
  comment: string
  pros?: string | null
  cons?: string | null
  is_verified_purchase: boolean
  status?: string
  helpful_votes_count?: number
  not_helpful_votes_count?: number
  product?: ProductCard
  created_at: string
}

export interface ProductReviewSummary {
  average_rating: number
  total_reviews: number
  reviews_count: number
  verified_reviews_count: number
  rating_distribution: Record<number, number>
}

export interface BlogCategory {
  id: number
  parent_id?: number | null
  name: string
  slug: string
  description?: string | null
  image_path?: string | null
  sort_order?: number
  meta_title?: string | null
  meta_description?: string | null
  children?: BlogCategory[]
}

export interface BlogTag {
  id: number
  name: string
  slug: string
}

export interface BlogPost {
  id: number
  title: string
  slug: string
  excerpt?: string | null
  featured_image?: string | null
  published_at?: string | null
  reading_time?: number | null
  views_count?: number
  category?: BlogCategory | null
  tags?: BlogTag[]
  seo?: Record<string, unknown>
}

export interface BlogPostDetail extends BlogPost {
  content: string
  related_products?: ProductCard[]
  related_categories?: Category[]
  related_brands?: Brand[]
  structured_data?: Record<string, unknown>
}

export interface CmsResponsiveDeviceSettings {
  visible: boolean
  layout: {
    width: string
    max_width?: string | null
    columns: number
    spacing: string
    alignment: string
  }
  typography: {
    heading_size: string
    subtitle_size: string
    text_size: string
    custom_heading_size?: string | null
    custom_subtitle_size?: string | null
    custom_text_size?: string | null
  }
  buttons: {
    layout: string
    alignment: string
    full_width: boolean
  }
  spacing: {
    padding: Record<string, string | null>
    margin: Record<string, string | null>
  }
  height?: string | null
  carousel: {
    slides_per_view: number
  }
  ordering: {
    media_first: boolean
  }
}

export interface CmsBlock {
  id: string | number
  type: string
  data: Record<string, any>
  responsive: {
    desktop: CmsResponsiveDeviceSettings
    tablet: CmsResponsiveDeviceSettings
    mobile: CmsResponsiveDeviceSettings
  }
  images: {
    desktop?: string | null
    tablet?: string | null
    mobile?: string | null
  }
  resolved?: {
    products?: ProductCard[]
    categories?: Category[]
    brands?: Brand[]
    bundles?: ProductBundle[]
  }
  preview?: {
    modes: string[]
    default_mode: string
  }
}

export interface ContentPage {
  id: number
  title: string
  slug: string
  page_type: string
  published_at?: string | null
  seo?: Record<string, unknown>
  schema?: Record<string, unknown> | null
  responsive_profiles?: Record<string, { label: string; min_width?: number | null; max_width?: number | null }>
  preview_modes?: string[]
  blocks: CmsBlock[]
}

export interface ContentTemplate {
  id: number
  name: string
  slug: string
  description?: string | null
  template_data: Record<string, unknown>
}

export interface ServiceTicket {
  id: number
  ticket_number: string
  ticket_type: string
  status: string
  priority: string
  subject: string
  description: string
  serial_number?: string | null
  purchased_at?: string | null
  warranty_expires_at?: string | null
  warranty?: { in_warranty: boolean; valid_until?: string | null }
  diagnosis?: string | null
  resolution?: string | null
  repair_date?: string | null
  refund_amount?: string | number | null
  refund_date?: string | null
  closed_at?: string | null
  order?: { id: number; order_number: string } | null
  product?: { id: number; name: string; slug: string; sku: string; warranty_months?: number | null } | null
  messages?: ServiceTicketMessage[]
  files?: ServiceTicketFile[]
  created_at: string
  updated_at: string
}

export interface ServiceTicketMessage {
  id: number
  message: string
  author?: string | null
  created_at: string
}

export interface ServiceTicketFile {
  id: number
  file_type: string
  file_size: number
  uploaded_by?: number | null
  created_at: string
}

export interface SeoPage {
  id: number
  title: string
  slug: string
  type: string
  content: string | CmsBlock[]
  responsive_profiles?: Record<string, { label: string; min_width?: number | null; max_width?: number | null }>
  preview_modes?: string[]
  published_at?: string | null
  related_category?: Category | null
  related_brand?: Brand | null
  related_products?: ProductCard[]
  related_categories?: Category[]
  related_brands?: Brand[]
  seo?: Record<string, unknown>
  schema_type?: string | null
  schema_data?: Record<string, unknown> | null
}

export interface AiMessage {
  id: number
  role: 'user' | 'assistant' | 'system'
  content: string
  metadata?: Record<string, unknown> | null
  created_at: string
}

export interface AiConversation {
  id: number
  title?: string | null
  session_id?: string | null
  messages: AiMessage[]
  created_at: string
  updated_at: string
}

export interface AiRecommendation {
  query: string
  intent: Record<string, unknown>
  summary: string
  reasoning: string[]
  products: ProductCard[]
}

export type PcComponentType =
  | 'cpu'
  | 'motherboard'
  | 'ram'
  | 'gpu'
  | 'psu'
  | 'case'
  | 'storage'
  | 'cooler'
  | 'operating_system'
  | 'monitor'
  | 'keyboard'
  | 'mouse'
  | 'speakers'
  | 'accessories'

export interface PcBuildItem {
  id: number
  component_type: PcComponentType
  quantity: number
  product: ProductCard
}

export interface PcBuild {
  id: number
  name: string
  description?: string | null
  total_price: string | number
  status: 'draft' | 'saved' | 'shared' | 'ordered'
  session_id?: string | null
  items: PcBuildItem[]
  created_at: string
  updated_at: string
}

export interface PcCompatibility {
  compatible: boolean
  warnings: string[]
  errors: string[]
  recommendations: string[]
}

export interface PcBuilderPreset {
  name: string
  budget: string
  focus: string[]
}

export interface PcBuilderMeta {
  component_types: PcComponentType[]
  statuses: string[]
  templates: PcBuilderPreset[]
}
