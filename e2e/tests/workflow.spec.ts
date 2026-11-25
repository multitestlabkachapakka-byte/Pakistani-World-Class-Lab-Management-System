import { readFileSync } from 'node:fs'
import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test'

const BASE = 'http://127.0.0.1:4173'

const ADMIN = { name: 'E2E Admin', username: 'e2e_admin', password: 'Admin-pass1' }
const STAFF = {
  pathologist: { name: 'API Pathologist', username: 'api_pathologist', password: 'Path-pass1', role: 'pathologist' },
  technician: { name: 'API Technician', username: 'api_technician', password: 'Tech-pass1', role: 'technician' },
  receptionist: { name: 'API Receptionist', username: 'api_receptionist', password: 'Recep-pass1', role: 'receptionist' },
}
const PATIENT = { name: 'E2E Patient', phone: '03001234567', address: 'E2E Street' }
const BACKUP_PASSPHRASE = 'e2e-backup-passphrase'

// The tests share one database and run in order.
test.describe.configure({ mode: 'serial' })

const opened: Page[] = []

test.afterEach(async ({}, info) => {
  const last = opened[opened.length - 1]
  if (last && info.status !== info.expectedStatus) {
    const text = await last.locator('body').innerText().catch(() => '(page not readable)')
    console.log(`--- page at failure: ${last.url()}\n${text.slice(0, 2500)}`)
  }
  for (const page of opened.splice(0)) await page.context().close().catch(() => undefined)
})

async function newPage(browser: Browser): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ baseURL: BASE })
  const page = await context.newPage()
  opened.push(page)
  return { context, page }
}

/** Signs in through the API (fast, and keeps the login rate limit out of the way) and opens a page as that user. */
async function openAs(browser: Browser, username: string, password: string): Promise<{ page: Page; token: string }> {
  const context = await browser.newContext({ baseURL: BASE })
  const res = await context.request.post('/api/auth/login', { data: { username, password } })
  expect(res.ok(), `API login as ${username} -> ${res.status()}`).toBeTruthy()
  const { data } = await res.json()

  await context.addInitScript(
    ([token, user]: [string, string]) => {
      localStorage.setItem('lims.token', token)
      localStorage.setItem('lims.user', user)
    },
    [data.token, JSON.stringify(data.user)] as [string, string],
  )

  const page = await context.newPage()
  opened.push(page)
  return { page, token: data.token as string }
}

test('1. first-time setup creates the administrator; sign out and sign in work', async ({ browser }) => {
  const { page } = await newPage(browser)

  await page.goto('/')
  await expect(page).toHaveURL(/\/login/)
  await expect(page.getByRole('heading', { name: 'First-time setup' })).toBeVisible()

  await page.getByLabel('Full name').fill(ADMIN.name)
  await page.getByLabel('Username').fill(ADMIN.username)
  await page.getByLabel('Password', { exact: true }).fill(ADMIN.password)
  await page.getByLabel('Confirm password').fill(ADMIN.password)
  await page.getByRole('button', { name: 'Create administrator' }).click()

  await expect(page.getByRole('button', { name: 'Sign out' })).toBeVisible()
  await expect(page.getByText(ADMIN.name)).toBeVisible()

  await page.getByRole('button', { name: 'Sign out' }).click()
  await expect(page).toHaveURL(/\/login/)
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible()

  await page.getByLabel('Username').fill(ADMIN.username)
  await page.getByLabel('Password').fill('Wrong-pass9')
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page.getByText('Invalid username or password.')).toBeVisible()

  await page.getByLabel('Password').fill(ADMIN.password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page.getByRole('button', { name: 'Sign out' })).toBeVisible()
})

test('2. staff accounts are created through the API for the next steps', async ({ browser }) => {
  const { page, token } = await openAs(browser, ADMIN.username, ADMIN.password)

  for (const staff of Object.values(STAFF)) {
    const res = await page.context().request.post('/api/users', {
      data: staff,
      headers: { Authorization: `Bearer ${token}` },
    })
    expect(res.status(), `creating ${staff.username}`).toBe(201)
  }
})

test('3. an administrator creates a pathologist account on the Staff screen', async ({ browser }) => {
  const { page } = await openAs(browser, ADMIN.username, ADMIN.password)
  await page.goto('/')

  await page.getByRole('link', { name: 'Staff' }).click()
  await expect(page.getByRole('heading', { name: 'Staff accounts' })).toBeVisible()
  await expect(page.getByRole('row', { name: /api_pathologist/ })).toBeVisible()

  const form = page.locator('form.card')
  await form.getByLabel('Full name').fill('Dr UI Created')
  await form.getByLabel('Username').fill('dr_ui')
  await form.getByLabel('Role').selectOption('pathologist')
  await form.getByLabel('Password').fill('Ui-pass-12')
  await form.getByRole('button', { name: 'Create account' }).click()

  await expect(page.getByText('Account created for Dr UI Created.')).toBeVisible()
  await expect(page.getByRole('row', { name: /dr_ui/ })).toBeVisible()
})

test('4. the Staff screen is for administrators only', async ({ browser }) => {
  const { page } = await openAs(browser, STAFF.technician.username, STAFF.technician.password)
  await page.goto('/')

  await expect(page.getByRole('link', { name: 'Catalog' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Staff' })).toHaveCount(0)

  await page.goto('/staff')
  await expect(page).not.toHaveURL(/\/staff/)
})

test('5. a pathologist verifies the ranges of a test; a technician cannot', async ({ browser }) => {
  const { page } = await openAs(browser, STAFF.pathologist.username, STAFF.pathologist.password)
  await page.goto('/')
  await page.getByRole('link', { name: 'Catalog' }).click()
  await expect(page.getByRole('heading', { name: 'Test Catalog' })).toBeVisible()

  await page.getByPlaceholder('Search by name, code or department').fill('CBC')
  const cbcRow = page.locator('table.list tbody tr').filter({ hasText: 'Complete Blood Count' }).first()
  await cbcRow.click()
  await expect(page.getByRole('heading', { level: 3, name: /Complete Blood Count/ })).toBeVisible()
  await expect(cbcRow).toContainText('Pending review')

  page.once('dialog', (dialog) => dialog.accept())
  await page.getByRole('button', { name: 'Verify all ranges of this test' }).click()
  await expect(page.getByText('All ranges of this test verified.')).toBeVisible()
  await expect(cbcRow).toContainText('Verified')

  // A technician sees the catalog but gets no verification or editing buttons.
  const tech = await openAs(browser, STAFF.technician.username, STAFF.technician.password)
  await tech.page.goto('/catalog')
  await tech.page.getByPlaceholder('Search by name, code or department').fill('CBC')
  await tech.page.locator('table.list tbody tr').filter({ hasText: 'Complete Blood Count' }).first().click()
  await expect(tech.page.getByRole('heading', { level: 3, name: /Complete Blood Count/ })).toBeVisible()
  await expect(tech.page.getByRole('button', { name: 'Verify all ranges of this test' })).toHaveCount(0)
  await expect(tech.page.getByRole('button', { name: 'Edit' })).toHaveCount(0)
})

test('6. technician: register patient, enter results, finalize, print - and the printed report stays frozen', async ({ browser }) => {
  const { page, token } = await openAs(browser, STAFF.technician.username, STAFF.technician.password)
  await page.goto('/')

  // --- register the patient
  await page.getByRole('link', { name: 'Patients' }).click()
  await page.getByRole('button', { name: '+ Register Patient' }).click()
  await page.getByLabel('Name *').fill(PATIENT.name)
  await page.getByLabel('Gender *').selectOption('male')
  await page.getByLabel('Age (years)').fill('30')
  await page.getByLabel('Phone *').fill(PATIENT.phone)
  await page.getByLabel('Address *').fill(PATIENT.address)
  await page.getByRole('button', { name: 'Save Patient' }).click()

  const patientRow = page.getByRole('row', { name: new RegExp(PATIENT.name) })
  await expect(patientRow).toBeVisible()
  const href = await patientRow.getByRole('link', { name: 'New Report' }).getAttribute('href')
  const patientId = new URL(href!, BASE).searchParams.get('patient')!
  expect(patientId).toBeTruthy()

  // --- choose the test
  await patientRow.getByRole('link', { name: 'New Report' }).click()
  await expect(page.locator('.selected-patient')).toContainText(PATIENT.name)
  await page.getByPlaceholder('CBC, LFT, TFT').fill('LFT')
  await page.locator('ul.hits li').filter({ hasText: 'LFT' }).first().click()
  await expect(page.locator('.chip')).toContainText('LFT')
  await page.getByRole('button', { name: 'Load Tests & Enter Results' }).click()
  await expect(page.getByRole('heading', { name: /3\. Results/ })).toBeVisible()

  const row = (name: string) => page.locator('tr').filter({ has: page.getByText(name, { exact: true }) })
  const input = (name: string) => row(name).locator('input.result-input')

  // results start empty: nothing is filled in for the technician
  await expect(input('Total Bilirubin')).toHaveValue('')
  await expect(input('Indirect Bilirubin')).toHaveValue('')

  // --- calculated results come from the entered values only
  await input('Total Bilirubin').fill('1.2')
  await expect(input('Indirect Bilirubin')).toHaveValue('') // direct bilirubin not entered yet
  await input('Direct Bilirubin').fill('0.3')
  await expect(input('Indirect Bilirubin')).toHaveValue('0.9')
  await expect(row('Indirect Bilirubin').getByText('calculated', { exact: true })).toBeVisible()

  // a hand-typed value wins, and can be given back to the calculation
  await input('Indirect Bilirubin').fill('0.8')
  await expect(row('Indirect Bilirubin').getByText('manual', { exact: true })).toBeVisible()
  await row('Indirect Bilirubin').getByRole('button', { name: 'recalculate' }).click()
  await expect(input('Indirect Bilirubin')).toHaveValue('0.9')

  await input('Total Protein').fill('7.0')
  await input('Albumin').fill('4.0')
  await expect(input('Globulin')).toHaveValue('3')
  await expect(input('A/G Ratio')).toHaveValue('1.33')

  // --- a draft is marked DRAFT
  const [draft] = await Promise.all([page.waitForEvent('popup'), page.getByRole('button', { name: 'Preview / Print' }).click()])
  await draft.waitForURL(/^blob:/)
  expect(await draft.content()).toContain('DRAFT')
  await draft.close()

  // --- finalize: missing results are listed and need an explicit decision
  await page.getByRole('button', { name: 'Finalize Report' }).click()
  await expect(page.getByText('These selected parameters have no result:')).toBeVisible()
  await page.getByRole('button', { name: 'Finalize anyway (incomplete)' }).click()
  await expect(page.getByText('Report finalized. Snapshot locked.')).toBeVisible()
  await expect(input('Indirect Bilirubin')).toHaveValue('0.9')
  await expect(input('Indirect Bilirubin')).toBeDisabled()

  // --- the printed report
  const [popup] = await Promise.all([page.waitForEvent('popup'), page.getByRole('button', { name: 'Preview / Print' }).click()])
  await popup.waitForURL(/^blob:/)
  const html = await popup.content()
  for (const text of [
    'LABORATORY REPORT', PATIENT.name, 'Indirect Bilirubin', '<td class="val">0.9</td>', '<td class="val">1.33</td>',
    'End of Report', 'Some requested results were not available', '<sup>†</sup>',
    'Reference range pending verification by the laboratory pathologist',
  ]) {
    expect(html, `printed report should contain: ${text}`).toContain(text)
  }
  expect(html).not.toContain('DRAFT')
  await popup.close()

  // --- a finalized report never changes: rename the patient, print again
  const rename = await page.context().request.put(`/api/patients/${patientId}`, {
    data: { name: 'Renamed Later' },
    headers: { Authorization: `Bearer ${token}` },
  })
  expect(rename.ok()).toBeTruthy()

  const [again] = await Promise.all([page.waitForEvent('popup'), page.getByRole('button', { name: 'Preview / Print' }).click()])
  await again.waitForURL(/^blob:/)
  const frozen = await again.content()
  expect(frozen).toContain(PATIENT.name)
  expect(frozen).not.toContain('Renamed Later')
})

test('7. a receptionist can book tests but cannot enter results or finalize', async ({ browser }) => {
  const { page } = await openAs(browser, STAFF.receptionist.username, STAFF.receptionist.password)
  await page.goto('/reports/create')

  await page.getByPlaceholder('Type name, phone or MR No').fill('Renamed')
  await page.locator('ul.hits li').filter({ hasText: 'Renamed Later' }).first().click()
  await page.getByPlaceholder('CBC, LFT, TFT').fill('LFT')
  await page.locator('ul.hits li').filter({ hasText: 'LFT' }).first().click()
  await page.getByRole('button', { name: 'Load Tests & Enter Results' }).click()
  await expect(page.getByRole('heading', { name: /3\. Results/ })).toBeVisible()

  await expect(page.locator('input.result-input').first()).toBeDisabled()
  await expect(page.getByRole('button', { name: 'Finalize Report' })).toHaveCount(0)
  await expect(page.getByText('Entering results and finalizing is done by a technician or pathologist.')).toBeVisible()
})

test('8. disaster recovery: backup, factory reset, first-time setup, restore - everything is back', async ({ browser }) => {
  const { page } = await openAs(browser, ADMIN.username, ADMIN.password)
  await page.goto('/')
  await page.getByRole('link', { name: 'Backup' }).click()
  await expect(page.getByRole('heading', { name: 'Backup & Restore' })).toBeVisible()

  // --- 1. download an encrypted backup
  const backup = page.getByTestId('backup-form')
  await backup.getByLabel('Passphrase', { exact: true }).fill(BACKUP_PASSPHRASE)
  await backup.getByLabel('Repeat passphrase').fill(BACKUP_PASSPHRASE)
  const [download] = await Promise.all([
    page.waitForEvent('download'),
    backup.getByRole('button', { name: 'Download backup' }).click(),
  ])
  expect(download.suggestedFilename()).toMatch(/^lims-backup-.*\.limsbk$/)
  const file = (await download.path())!
  const bytes = readFileSync(file)
  expect(bytes.subarray(0, 7).toString()).toBe('LIMSBK1')
  expect(bytes.includes('Renamed Later'), 'patient data must not be readable in the file').toBe(false)

  // --- 2. factory reset: everything is gone, the first-time setup starts again
  const reset = page.getByTestId('reset-form')
  await reset.getByLabel('Passphrase for the safety copy').fill(BACKUP_PASSPHRASE)
  await reset.getByLabel('Your password').fill(ADMIN.password)
  await reset.getByLabel('Type RESET ALL DATA to confirm').fill('RESET ALL DATA')
  await reset.getByRole('button', { name: 'Erase all data' }).click()
  await expect(page).toHaveURL(/\/login/)
  await expect(page.getByRole('heading', { name: 'First-time setup' })).toBeVisible()

  // --- 3. recover: a temporary administrator, then restore the backup
  await page.getByLabel('Full name').fill('Temporary Admin')
  await page.getByLabel('Username').fill('tmp_admin')
  await page.getByLabel('Password', { exact: true }).fill('Temp-pass-1')
  await page.getByLabel('Confirm password').fill('Temp-pass-1')
  await page.getByRole('button', { name: 'Create administrator' }).click()
  await expect(page.getByRole('button', { name: 'Sign out' })).toBeVisible()

  await page.getByRole('link', { name: 'Backup' }).click()
  await expect(page.getByText(/pre-reset-.*\.limsbk/)).toBeVisible() // the reset kept a safety copy

  const restore = page.getByTestId('restore-form')
  await restore.getByLabel('Backup file').setInputFiles(file)
  await restore.getByLabel('Passphrase').fill(BACKUP_PASSPHRASE)
  await restore.getByLabel('Your password').fill('Temp-pass-1')
  await restore.getByLabel('Type RESTORE to confirm').fill('RESTORE')
  await restore.getByRole('button', { name: 'Restore' }).click()
  await expect(page).toHaveURL(/\/login/)

  // --- 4. the original administrator, the staff, the patients and the verified ranges are back
  await page.getByLabel('Username').fill(ADMIN.username)
  await page.getByLabel('Password').fill(ADMIN.password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page.getByRole('button', { name: 'Sign out' })).toBeVisible()
  await expect(page.getByText(ADMIN.name)).toBeVisible()

  await page.getByRole('link', { name: 'Patients' }).click()
  await expect(page.getByRole('row', { name: /Renamed Later/ })).toBeVisible()

  await page.getByRole('link', { name: 'Staff' }).click()
  await expect(page.getByRole('row', { name: /api_technician/ })).toBeVisible()
  await expect(page.getByRole('row', { name: /tmp_admin/ })).toHaveCount(0)

  await page.getByRole('link', { name: 'Catalog' }).click()
  await page.getByPlaceholder('Search by name, code or department').fill('CBC')
  await expect(page.locator('table.list tbody tr').filter({ hasText: 'Complete Blood Count' }).first()).toContainText('Verified')
})
