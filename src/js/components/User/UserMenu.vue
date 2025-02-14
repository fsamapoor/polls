<!--
  - @copyright Copyright (c) 2018 René Gieling <github@dartcafe.de>
  -
  - @author René Gieling <github@dartcafe.de>
  -
  - @license GNU AGPL version 3 or any later version
  -
  - This program is free software: you can redistribute it and/or modify
  - it under the terms of the GNU Affero General Public License as
  - published by the Free Software Foundation, either version 3 of the
  - License, or (at your option) any later version.
  -
  - This program is distributed in the hope that it will be useful,
  - but WITHOUT ANY WARRANTY; without even the implied warranty of
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program.  If not, see <http://www.gnu.org/licenses/>.
  -
  -->

<template>
	<NcActions primary>
		<template #icon>
			<SettingsIcon :size="20" decorative />
		</template>
		<NcActionButton v-if="$route.name === 'publicVote'" @click="copyLink()">
			<template #icon>
				<ClippyIcon />
			</template>
			{{ t('polls', 'Copy your personal link to clipboard') }}
		</NcActionButton>
		<NcActionSeparator v-if="$route.name === 'publicVote'" />
		<NcActionInput v-if="$route.name === 'publicVote'"
			v-bind="userEmail.inputProps"
			:value.sync="userEmail.inputValue"
			@update:value="validateEmailAddress"
			@submit="submitEmailAddress">
			<template #icon>
				<EditEmailIcon />
			</template>
			{{ t('polls', 'Edit Email Address') }}
		</NcActionInput>
		<NcActionInput v-if="$route.name === 'publicVote' && acl.allowVote"
			v-bind="userName.inputProps"
			:value.sync="userName.inputValue"
			@update:value="validateDisplayName"
			@submit="submitDisplayName">
			<template #icon>
				<EditAccountIcon />
			</template>
			{{ t('polls', 'Change name') }}
		</NcActionInput>
		<NcActionButton v-if="$route.name === 'publicVote'"
			:disabled="!emailAddress"
			:value="emailAddress"
			@click="resendInvitation()">
			<template #icon>
				<SendLinkPerEmailIcon />
			</template>
			{{ t('polls', 'Get your personal link per mail') }}
		</NcActionButton>
		<NcActionCheckbox :checked="subscribed"
			:disabled="!acl.allowSubscribe"
			title="check"
			@change="toggleSubscription">
			{{ t('polls', 'Subscribe to notifications') }}
		</NcActionCheckbox>
		<NcActionButton v-if="$route.name === 'publicVote' && emailAddress"
			:disabled="!emailAddress"
			@click="deleteEmailAddress">
			<template #icon>
				<DeleteIcon />
			</template>
			{{ t('polls', 'Remove Email Address') }}
		</NcActionButton>
		<NcActionButton v-if="acl.allowEdit" @click="getAddresses()">
			<template #icon>
				<ClippyIcon />
			</template>
			{{ t('polls', 'Copy list of email addresses to clipboard') }}
		</NcActionButton>
		<NcActionButton v-if="acl.allowVote" @click="resetVotes()">
			<template #icon>
				<ResetVotesIcon />
			</template>
			{{ t('polls', 'Reset your votes') }}
		</NcActionButton>
		<NcActionButton v-if="$route.name === 'publicVote' && hasCookie" @click="logout()">
			<template #icon>
				<LogoutIcon />
			</template>
			{{ t('polls', 'Logout as {name} (delete cookie)', { name: acl.displayName }) }}
		</NcActionButton>
	</NcActions>
</template>

<script>
import { debounce } from 'lodash'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { NcActions, NcActionButton, NcActionCheckbox, NcActionInput, NcActionSeparator } from '@nextcloud/vue'
import { mapState } from 'vuex'
import SettingsIcon from 'vue-material-design-icons/Cog.vue'
import EditAccountIcon from 'vue-material-design-icons/AccountEdit.vue'
import EditEmailIcon from 'vue-material-design-icons/EmailEditOutline.vue'
import SendLinkPerEmailIcon from 'vue-material-design-icons/LinkVariant.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import ClippyIcon from 'vue-material-design-icons/ClipboardArrowLeftOutline.vue'
import ResetVotesIcon from 'vue-material-design-icons/Undo.vue'
import LogoutIcon from 'vue-material-design-icons/Logout.vue'
import { deleteCookieByValue, findCookieByValue } from '../../helpers/index.js'
import { ValidatorAPI, PollsAPI } from '../../Api/index.js'

const setError = (inputProps) => {
	inputProps.success = false
	inputProps.error = true
	inputProps.showTrailingButton = false
}

const setSuccess = (inputProps) => {
	inputProps.success = true
	inputProps.error = false
	inputProps.showTrailingButton = true
}
const setNeutral = (inputProps) => {
	inputProps.success = false
	inputProps.error = false
	inputProps.showTrailingButton = false
}

export default {
	name: 'UserMenu',

	components: {
		NcActions,
		NcActionButton,
		NcActionCheckbox,
		NcActionInput,
		NcActionSeparator,
		SettingsIcon,
		EditAccountIcon,
		EditEmailIcon,
		LogoutIcon,
		SendLinkPerEmailIcon,
		DeleteIcon,
		ClippyIcon,
		ResetVotesIcon,
	},

	data() {
		return {
			userEmail: {
				inputValue: '',
				inputProps: {
					success: false,
					error: false,
					showTrailingButton: true,
				},
			},
			userName: {
				inputValue: '',
				inputProps: {
					success: false,
					error: false,
					showTrailingButton: true,
				},
			},
		}
	},

	computed: {
		...mapState({
			acl: (state) => state.poll.acl,
			share: (state) => state.share,
			subscribed: (state) => state.subscription.subscribed,
			emailAddress: (state) => state.share.emailAddress,
			displayName: (state) => state.poll.acl.displayName,
		}),

		hasCookie() {
			return !!findCookieByValue(this.$route.params.token)
		},

		personalLink() {
			return window.location.origin
				+ this.$router.resolve({
					name: 'publicVote',
					params: { token: this.$route.params.token },
				}).href
		},
	},

	watch: {
		emailAddress() {
			this.userEmail.inputValue = this.emailAddress
		},
		displayName() {
			this.userName.inputValue = this.displayName
		},
	},

	created() {
		this.userEmail.inputValue = this.emailAddress
		this.userName.inputValue = this.displayName
	},

	methods: {
		logout() {
			const reRouteTo = deleteCookieByValue(this.$route.params.token)
			if (reRouteTo) {
				this.$router.push({ name: 'publicVote', params: { token: reRouteTo } })
			}
		},

		async toggleSubscription() {
			await this.$store.dispatch('subscription/update', !this.subscribed)
		},

		async deleteEmailAddress() {
			try {
				await this.$store.dispatch('share/deleteEmailAddress')
				showSuccess(t('polls', 'Email address deleted.'))
			} catch {
				showError(t('polls', 'Error deleting email address {emailAddress}', { emailAddress: this.userEmail.inputValue }))
			}
		},

		validateEmailAddress: debounce(async function() {
			const inputProps = this.userEmail.inputProps

			if (this.userEmail.inputValue === this.emailAddress) {
				setNeutral(inputProps)
				return
			}

			try {
				await ValidatorAPI.validateEmailAddress(this.userEmail.inputValue)
				setSuccess(inputProps)
			} catch {
				setError(inputProps)
			}
		}, 500),

		validateDisplayName: debounce(async function() {
			const inputProps = this.userName.inputProps
			if (this.userName.inputValue.length < 1) {
				setError(inputProps)
				return
			}

			if (this.userName.inputValue === this.displayName) {
				setNeutral(inputProps)
				return
			}

			try {
				await ValidatorAPI.validateName(this.$route.params.token, this.userName.inputValue)
				setSuccess(inputProps)
			} catch {
				setError(inputProps)
			}
		}, 500),

		async submitEmailAddress() {
			try {
				await this.$store.dispatch('share/updateEmailAddress', { emailAddress: this.userEmail.inputValue })
				showSuccess(t('polls', 'Email address {emailAddress} saved.', { emailAddress: this.userEmail.inputValue }))
				setNeutral(this.userEmail.inputProps)
			} catch {
				showError(t('polls', 'Error saving email address {emailAddress}', { emailAddress: this.userEmail.inputValue }))
				setError(this.userEmail.inputProps)
			}
		},

		async submitDisplayName() {
			try {
				await this.$store.dispatch('share/updateDisplayName', { displayName: this.userName.inputValue })
				setNeutral(this.userName.inputProps)
				showSuccess(t('polls', 'Name changed.'))
			} catch {
				showError(t('polls', 'Error changing name.'))
				setError(this.userName.inputProps)
			}
		},

		async resendInvitation() {
			try {
				const response = await this.$store.dispatch('share/resendInvitation')
				showSuccess(t('polls', 'Invitation resent to {emailAddress}', { emailAddress: response.data.share.emailAddress }))
			} catch {
				showError(t('polls', 'Mail could not be resent to {emailAddress}', { emailAddress: this.share.emailAddress }))
			}
		},

		async copyLink() {
			try {
				await navigator.clipboard.writeText(this.personalLink)
				showSuccess(t('polls', 'Link copied to clipboard'))
			} catch {
				showError(t('polls', 'Error while copying link to clipboard'))
			}
		},

		async getAddresses() {
			try {
				const response = await PollsAPI.getParticipantsEmailAddresses(this.$route.params.id)
				await navigator.clipboard.writeText(response.data.map((item) => item.combined))
				showSuccess(t('polls', 'Link copied to clipboard'))
			} catch (e) {
				if (e?.code === 'ERR_CANCELED') return
				showError(t('polls', 'Error while copying link to clipboard'))
			}
		},

		async resetVotes() {
			try {
				await this.$store.dispatch('votes/resetVotes')
				showSuccess(t('polls', 'Your votes are reset'))
			} catch {
				showError(t('polls', 'Error while resetting votes'))
			}
		},
	},
}
</script>
