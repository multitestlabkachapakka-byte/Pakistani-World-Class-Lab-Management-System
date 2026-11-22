import { AxiosError, type InternalAxiosRequestConfig } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import api, { onUnauthorized } from '../api'
import { session, type SessionUser } from '../session'

const user: SessionUser = { id: '1', name: 'Tech', username: 'tech', role: 'technician' }

/** Replaces the network with a fake that answers with the given status and records the requests. */
function fakeServer(status: number): InternalAxiosRequestConfig[] {
  const seen: InternalAxiosRequestConfig[] = []
  api.defaults.adapter = async (config) => {
    seen.push(config)
    const response = { data: {}, status, statusText: '', headers: {}, config }
    if (status >= 400) throw new AxiosError('failed', 'ERR_BAD_REQUEST', config, null, response)
    return response
  }
  return seen
}

beforeEach(() => {
  session.clear()
  onUnauthorized(() => {})
  vi.spyOn(console, 'error').mockImplementation(() => {})
})

describe('api client', () => {
  it('sends the stored login token as a Bearer header', async () => {
    session.save('abc123', user)
    const seen = fakeServer(200)

    await api.get('/ping')

    expect(seen[0].headers.get('Authorization')).toBe('Bearer abc123')
  })

  it('sends no Authorization header when signed out', async () => {
    const seen = fakeServer(200)

    await api.get('/ping')

    expect(seen[0].headers.get('Authorization')).toBeFalsy()
  })

  it('always identifies the device', async () => {
    const seen = fakeServer(200)

    await api.get('/ping')

    expect(seen[0].headers.get('X-Device-Code')).toBe('P')
  })

  it('signs the user out when the server rejects a token we hold (401)', async () => {
    session.save('expired', user)
    const handler = vi.fn()
    onUnauthorized(handler)
    fakeServer(401)

    await expect(api.get('/patients')).rejects.toBeTruthy()

    expect(handler).toHaveBeenCalledOnce()
  })

  it('does not treat a 401 without a token as an expired session', async () => {
    const handler = vi.fn()
    onUnauthorized(handler)
    fakeServer(401)

    await expect(api.get('/patients')).rejects.toBeTruthy()

    expect(handler).not.toHaveBeenCalled()
  })
})
