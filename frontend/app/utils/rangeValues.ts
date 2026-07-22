export interface BoundedRange {
  minimum: number
  maximum: number
}

export function boundedRange(
  availableMinimum: number,
  availableMaximum: number,
  step: number,
  selectedMinimum?: string | number | null,
  selectedMaximum?: string | number | null,
): BoundedRange {
  const minimum = boundedValue(selectedMinimum, availableMinimum, availableMaximum, step, availableMinimum)
  const maximum = boundedValue(selectedMaximum, availableMinimum, availableMaximum, step, availableMaximum)

  return minimum <= maximum
    ? { minimum, maximum }
    : { minimum: maximum, maximum }
}

export function normalizedRangeSelection(
  availableMinimum: number,
  availableMaximum: number,
  minimum: number,
  maximum: number,
): { min?: string; max?: string } {
  return {
    min: minimum > availableMinimum ? String(minimum) : undefined,
    max: maximum < availableMaximum ? String(maximum) : undefined,
  }
}

function boundedValue(
  value: string | number | null | undefined,
  minimum: number,
  maximum: number,
  step: number,
  fallback: number,
): number {
  const number = Number(value)

  if ((value === null || value === undefined || value === '') || !Number.isFinite(number)) {
    return fallback
  }

  const clamped = Math.min(maximum, Math.max(minimum, number))
  const precision = decimalPlaces(step)

  return Number(clamped.toFixed(precision))
}

function decimalPlaces(value: number): number {
  const text = String(value)

  return text.includes('.') ? text.split('.')[1]?.length || 0 : 0
}
