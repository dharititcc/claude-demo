import { api } from './api'
import type { Invitation, InvitationPreview, Member, Organization, Role } from '@/types'

export const teamService = {
  async members(): Promise<Member[]> {
    const { data } = await api.get<{ data: Member[] }>('/v1/members')
    return data.data
  },

  async invitations(): Promise<Invitation[]> {
    const { data } = await api.get<{ data: Invitation[] }>('/v1/members/invitations')
    return data.data
  },

  async invite(payload: { email: string; role: Role }): Promise<Invitation> {
    const { data } = await api.post<{ data: Invitation }>('/v1/members/invitations', payload)
    return data.data
  },

  async revokeInvitation(id: number): Promise<void> {
    await api.delete(`/v1/members/invitations/${id}`)
  },

  async updateRole(userId: number, role: Role): Promise<void> {
    await api.put(`/v1/members/${userId}/role`, { role })
  },

  async removeMember(userId: number): Promise<void> {
    await api.delete(`/v1/members/${userId}`)
  },

  /** Public: the invitee reads this before signing in. */
  async previewInvitation(token: string): Promise<InvitationPreview> {
    const { data } = await api.get<{ data: InvitationPreview }>(`/v1/invitations/${token}`)
    return data.data
  },

  async acceptInvitation(token: string): Promise<Organization> {
    const { data } = await api.post<{ data: Organization }>(`/v1/invitations/${token}/accept`)
    return data.data
  },
}

export const organizationService = {
  /**
   * Update settings. Sent as multipart because the logo is a file; the API
   * accepts POST for this reason (PHP does not populate $_FILES on PUT).
   */
  async update(payload: {
    name?: string
    timezone?: string
    currency?: string
    language?: string
    logo?: File | null
  }): Promise<Organization> {
    const form = new FormData()

    Object.entries(payload).forEach(([key, value]) => {
      if (value === undefined || value === null) return
      form.append(key, value as string | Blob)
    })

    const { data } = await api.post<{ data: Organization }>('/v1/organization', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })

    return data.data
  },
}
