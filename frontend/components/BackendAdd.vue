<template>
  <Message title="Important" message_class="has-background-warning-80 has-text-dark" icon="fas fa-info-circle">
    <ul>
      <li>
        If you are adding new backend that is fresh and doesn't have your current watch state, you should turn off
        import and enable only metadata import at the start to prevent overriding your current play state. Visit the
        following guide
        <NuxtLink to="/help/one-way-sync">
          <span class="icon"><i class="fas fa-circle-question" /></span> One-way sync
        </NuxtLink> to learn more.
      </li>
      <li v-if="api_user === 'main'">
        Do not add sub-users backends manually, after finishing the main user backends setup. Visit
        <NuxtLink target="_blank" to="/tools/sub_users">
          <span class="icon"><i class="fas fa-tools" /></span> Tools >
          <span class="icon"><i class="fas fa-users" /></span> Sub-users
        </NuxtLink> page to create their own user and backends automatically.
      </li>
    </ul>
  </Message>

  <form id="backend_add_form" @submit.prevent="stage < 4 ? changeStep() : addBackend()">
    <div class="card">
      <div class="card-header">
        <p class="card-header-title">Add backend to '<u class="has-text-danger">{{ api_user }}</u>' user config.</p>
      </div>

      <div class="card-content">
        <div class="field" v-if="error">
          <Message title="Backend Error" id="backend_error" message_class="has-background-danger-80 has-text-dark"
            icon="fas fa-exclamation-triangle" useClose @close="error = null">
            <p>{{ error }}</p>
          </Message>
        </div>
        <template v-if="stage >= 0">

          <div class="field">
            <label class="label">Local User</label>
            <div class="control has-icons-left">
              <div class="select is-fullwidth">
                <select class="is-capitalized" disabled>
                  <option v-text="api_user" />
                </select>
              </div>
              <div class="icon is-left">
                <i class="fas fa-users"></i>
              </div>
            </div>
            <p class="help">
              The local user which this backend will be associated with. You can change this user via the
              <span class="icon"><i class="fas fa-users" /></span> users icon on top right of the page.
            </p>
          </div>

          <div class="field">
            <label class="label">Type</label>
            <div class="control has-icons-left">
              <div class="select is-fullwidth">
                <select v-model="backend.type" class="is-capitalized" required :disabled="stage > 0">
                  <option v-for="type in supported" :key="'type-' + type" :value="type">
                    {{ type }}
                  </option>
                </select>
              </div>
              <div class="icon is-left">
                <i class="fas fa-server"></i>
              </div>
            </div>
            <p class="help">The backend type.</p>
          </div>

          <div class="field">
            <label class="label">Name</label>
            <div class="control has-icons-left">
              <input class="input" type="text" v-model="backend.name" required :disabled="stage > 0">
              <div class="icon is-left">
                <i class="fas fa-id-badge"></i>
              </div>
              <p class="help">
                Choose a unique name for this backend. <b class="has-text-danger">You CANNOT change it later</b>.
                Backend name must be in <code>lower case a-z, 0-9 and _</code> and cannot start with number.
              </p>
            </div>
          </div>

          <div class="field">
            <label class="label">
              <template v-if="'plex' !== backend.type">API Key</template>
              <template v-else>X-Plex-Token</template>
            </label>
            <div class="field-body">
              <div class="field">
                <div class="field has-addons">
                  <div class="control is-expanded has-icons-left">
                    <input class="input" v-model="backend.token" required :disabled="stage > 1"
                      :type="false === exposeToken ? 'password' : 'text'">
                    <div class="icon is-left">
                      <i class="fas fa-key"></i>
                    </div>
                  </div>
                  <div class="control">
                    <button type="button" class="button is-primary" @click="exposeToken = !exposeToken"
                      v-tooltip="'Toggle token'">
                      <span class="icon" v-if="!exposeToken"><i class="fas fa-eye"></i></span>
                      <span class="icon" v-else><i class="fas fa-eye-slash"></i></span>
                    </button>
                  </div>
                </div>
                <p class="help">
                  <template v-if="'plex' === backend.type">
                    Enter the <code>X-Plex-Token</code>.
                    <NuxtLink target="_blank" to="https://support.plex.tv/articles/204059436"
                      v-text="'Visit This link'" /> to learn how to get the token. <span
                      class="is-bold has-text-danger">If you plan to add sub-users, YOU MUST use admin level
                      token.</span>
                  </template>
                  <template v-else>
                    Generate a new API Key from <code>Dashboard > Settings > API Keys</code>.<br>
                    <span class="icon has-text-warning"><i class="fas fa-info-circle"></i></span>
                    You can use <code>username:password</code> as API key and we will automatically generate limited
                    token if you are unable to generate API Key. This should be used as last resort. and it's mostly
                    untested. and things might not work as expected.
                    <span class="is-bold has-text-danger">If you plan to add sub-users, YOU MUST use API KEY and not
                      username:password.</span>
                  </template>
                </p>
              </div>
            </div>
          </div>

          <template v-if="'plex' === backend.type">
            <label class="label">User PIN</label>
            <div class="control has-icons-left">
              <input class="input" type="text" v-model="backend.options.PLEX_USER_PIN" :disabled="stage > 1">
              <div class="icon is-left"><i class="fas fa-key"></i></div>
              <p class="help">
                If the user you are going to select has <code>PIN</code> enabled, you need to enter the pin here.
                Otherwise it will fail to authenticate.
              </p>
            </div>
          </template>
        </template>

        <template v-if="stage >= 1">
          <div class="field" v-if="'plex' !== backend.type">
            <label class="label">URL</label>
            <div class="control has-icons-left">
              <input class="input" type="text" v-model="backend.url" required :disabled="stage > 1">
              <div class="icon is-left"><i class="fas fa-link"></i></div>
              <p class="help">
                Enter the URL of the backend. For example <code>http://192.168.8.200:8096</code>.
              </p>
            </div>
          </div>

          <div class="field" v-else>
            <label class="label">Plex Server URL</label>
            <div class="control has-icons-left">
              <div class="select is-fullwidth">
                <select v-model="backend.url" class="is-capital" @change="stage = 1; updateIdentifier()" required
                  :disabled="stage > 1">
                  <option value="" disabled>Select Server URL</option>
                  <option v-for="server in servers" :key="'server-' + server.uuid" :value="server.uri">
                    {{ server.name }} - {{ server.uri }}
                  </option>
                </select>
              </div>
              <div class="icon is-left">
                <i class="fas fa-link" v-if="!serversLoading"></i>
                <i class="fas fa-spinner fa-pulse" v-else></i>
              </div>
              <p class="help">
                <NuxtLink @click="getServers" v-text="'Attempt to discover servers associated with the token.'"
                  v-if="stage < 2" />
                Try to use non <code>.plex.direct</code> urls if possible, as they are often have problems working in
                docker. If you use custom domain for your plex server and it's not showing in the list, you can add it
                via Plex settings page. <code>Plex > Settings > Network > <strong>Custom server access
                URLs:</strong></code>. For more information
                <NuxtLink target="_blank"
                  to="https://support.plex.tv/articles/200430283-network/#Custom-server-access-URLs"
                  v-text="'Visit this link'" />
                .
              </p>
            </div>
          </div>
        </template>

        <div class="field" v-if="stage >= 3">
          <label class="label">
            Associated User
          </label>
          <div class="control has-icons-left">
            <div class="select is-fullwidth">
              <select v-model="backend.user" class="is-capitalized" :disabled="stage > 3">
                <option value="" disabled>Select User</option>
                <option v-for="user in users" :key="'uid-' + user.id" :value="user.id">
                  {{ user.name }}
                </option>
              </select>
            </div>
            <div class="icon is-left">
              <i class="fas fa-user-tie" v-if="!usersLoading"></i>
              <i class="fas fa-spinner fa-pulse" v-else></i>
            </div>
            <p class="help">
              Which user we should associate this backend with?
              <NuxtLink @click="getUsers" v-text="'Retrieve User ids from backend.'" v-if="stage < 4" />
            </p>
          </div>
        </div>

        <template v-if="stage >= 4">
          <div class="field" v-if="backend.import">
            <label class="label" for="backend_import">Import data from this backend</label>
            <div class="control">
              <input id="backend_import" type="checkbox" class="switch is-success" v-model="backend.import.enabled">
              <label for="backend_import">Enable</label>
              <p class="help">
                Import means to get the data from the backend and store it in the database.
              </p>
            </div>
          </div>

          <div class="field" v-if="backend.import && !backend.import.enabled">
            <label class="label" for="backend_import_metadata">Import metadata only from from this backend?</label>
            <div class="control">
              <input id="backend_import_metadata" type="checkbox" class="switch is-success"
                v-model="backend.options.IMPORT_METADATA_ONLY">
              <label for="backend_import_metadata">Enable</label>
              <p class="help has-text-danger">
                To efficiently push changes to the backend we need relation map and this require
                us to get metadata from the backend. You have Importing disabled, as such this option
                allow us to import this backend metadata without altering your play state.
              </p>
            </div>
          </div>

          <div class="field" v-if="backend.export">
            <label class="label" for="backend_export">Export data to this backend</label>
            <div class="control">
              <input id="backend_export" type="checkbox" class="switch is-success" v-model="backend.export.enabled">
              <label for="backend_export">Enable</label>
              <p class="help">
                Export means to send the data from the database to this backend.
              </p>
            </div>
          </div>

          <div class="field" v-if="backend.webhook">
            <label class="label" for="webhook_match_user">Webhook match user</label>
            <div class="control">
              <input id="webhook_match_user" type="checkbox" class="switch is-success"
                v-model="backend.webhook.match.user">
              <label for="webhook_match_user">Enable</label>
              <p class="help">
                Check webhook payload for user id match. if it does not match, the payload will be ignored.
              </p>
            </div>
          </div>

          <div class="field" v-if="backend.webhook">
            <label class="label" for="webhook_match_uuid">Webhook match backend id</label>
            <div class="control">
              <input id="webhook_match_uuid" type="checkbox" class="switch is-success"
                v-model="backend.webhook.match.uuid">
              <label for="webhook_match_uuid">Enable</label>
              <p class="help">
                Check webhook payload for backend unique id. if it does not match, the payload will be ignored.
              </p>
            </div>
          </div>

          <div class="field">
            <hr>
            <label class="label has-text-danger" for="backup_data">
              Create backup for this backend data?
            </label>
            <div class="control">
              <input id="backup_data" type="checkbox" class="switch is-success" v-model="backup_data">
              <label for="backup_data">Yes</label>
              <p class="help">
                This will run a one time backup for the backend data.
              </p>
            </div>
          </div>

          <div class="field" v-if="backends.length < 1">
            <hr>
            <label class="label" for="force_import">
              Force one time import from this backend?
            </label>
            <div class="control">
              <input id="force_import" type="checkbox" class="switch is-success" v-model="force_import">
              <label for="force_import">Yes</label>
              <p class="help">
                <span class="icon"><i class="fas fa-info-circle"></i></span>
                Run a one time import from this backend after adding it.
              </p>
            </div>
          </div>

          <div class="field" v-if="backends.length > 0">
            <hr>
            <label class="label has-text-danger" for="force_export">
              Force Export local data to this backend?
            </label>
            <div class="control">
              <input id="force_export" type="checkbox" class="switch is-success" v-model="force_export">
              <label for="force_export">Yes</label>
              <p class="help has-text-danger">
                <span class="icon"><i class="fas fa-info-circle"></i></span>
                THIS OPTION WILL OVERRIDE THE BACKEND DATA with locally stored data.
              </p>
            </div>
          </div>

        </template>
      </div>

      <div class="card-footer">

        <div class="card-footer-item" v-if="stage >= 1">
          <button class="button is-fullwidth is-warning" type="button" @click="stage = stage - 1">
            <span class="icon"><i class="fas fa-arrow-left"></i></span>
            <span>Previous Step</span>
          </button>
        </div>

        <div class="card-footer-item" v-if="stage < maxStages">
          <button class="button is-fullwidth is-info" type="button" @click="changeStep()">
            <span class="icon"><i class="fas fa-arrow-right"></i></span>
            <span>Next Step</span>
          </button>
        </div>
        <div class="card-footer-item" v-else>
          <button class="button is-fullwidth is-primary" type="submit">
            <span class="icon"><i class="fas fa-plus"></i></span>
            <span>Add Backend</span>
          </button>
        </div>
      </div>
    </div>
  </form>
</template>

<script setup>
import 'assets/css/bulma-switch.css'
import request from '~/utils/request'
import { awaitElement, explode, notification, ucFirst } from '~/utils/index'
import { useStorage } from "@vueuse/core";

const emit = defineEmits(['addBackend', 'backupData', 'forceExport', 'forceImport'])

const props = defineProps({
  backends: {
    type: Array,
    required: true
  }
})

const backend = ref({
  name: '',
  type: 'plex',
  url: '',
  token: '',
  uuid: '',
  user: '',
  import: {
    enabled: false
  },
  export: {
    enabled: false
  },
  webhook: {
    match: {
      user: false,
      uuid: false
    }
  },
  options: {}
})
const api_user = useStorage('api_user', 'main')
const users = ref([])
const supported = ref([])
const servers = ref([])

const maxStages = 5
const stage = ref(0)
const usersLoading = ref(false)
const uuidLoading = ref(false)
const serversLoading = ref(false)
const exposeToken = ref(false)
const error = ref()
const backup_data = ref(true)
const force_export = ref(false)
const force_import = ref(false)

const isLimited = ref(false)
const accessTokenResponse = ref({})

const getUUid = async () => {
  const required_values = ['type', 'token', 'url'];

  if (true === isLimited.value || Object.keys(accessTokenResponse.value) > 0) {
    return
  }

  if (required_values.some(v => !backend.value[v])) {
    notification('error', 'Error', `Please fill all the required fields. ${required_values.join(', ')}.`)
    return
  }

  try {
    error.value = null
    uuidLoading.value = true
    let data = {
      name: backend.value?.name,
      token: backend.value.token,
      url: backend.value.url
    }

    if (backend.value.user) {
      data.user = backend.value.user
    }

    const response = await request(`/backends/uuid/${backend.value.type}`, {
      method: 'POST',
      body: JSON.stringify(data)
    })

    const json = await response.json()

    if (200 !== response.status) {
      n_proxy('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }

    backend.value.uuid = json.identifier

    return backend.value.uuid
  } catch (e) {
    n_proxy('error', 'Error', `Request error. ${e.message}`, e)
  } finally {
    uuidLoading.value = false
  }
}

const getAccessToken = async () => {
  const required_values = ['type', 'token', 'url'];

  if (required_values.some(v => !backend.value[v])) {
    notification('error', 'Error', `Please fill all the required fields. ${required_values.join(', ')}.`)
    return
  }

  if (Object.keys(accessTokenResponse.value) > 0) {
    return
  }

  const [username, password] = explode(':', backend.value.token, 2)

  if (!username || !password) {
    return
  }

  try {
    error.value = null

    const response = await request(`/backends/accesstoken/${backend.value.type}`, {
      method: 'POST',
      body: JSON.stringify({
        name: backend.value?.name,
        url: backend.value.url,
        username: username,
        password: password,
      })
    })

    const json = await response.json()

    if (200 !== response.status) {
      n_proxy('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }

    accessTokenResponse.value = json
    backend.value.token = json?.accesstoken
    backend.value.user = json?.user
    backend.value.uuid = json?.identifier
    users.value = [{
      id: json?.user,
      name: username
    }]

    isLimited.value = true
    return true
  } catch (e) {
    n_proxy('error', 'Error', `Request error. ${e.message}`, e)
    return false
  }
}

const getUsers = async (showAlert = true) => {
  const required_values = ['type', 'token', 'url', 'uuid']

  if (required_values.some(v => !backend.value[v])) {
    if (showAlert) {
      notification('error', 'Error', `Please fill all the required fields. ${required_values.join(', ')}.`)
    }
    return
  }

  try {
    error.value = null
    usersLoading.value = true

    let data = {
      name: backend.value?.name,
      token: backend.value.token,
      url: backend.value.url,
      uuid: backend.value.uuid,
    };

    if (backend.value.options && backend.value.options.ADMIN_TOKEN) {
      data.options = {
        ADMIN_TOKEN: backend.value.options.ADMIN_TOKEN
      }
    }

    if (backend.value.options && backend.value.options.PLEX_USER_PIN) {
      data.options = {
        PLEX_USER_PIN: backend.value.options.PLEX_USER_PIN
      }
    }

    const response = await request(`/backends/users/${backend.value.type}?tokens=1`, {
      method: 'POST',
      body: JSON.stringify(data)
    })

    const json = await response.json()

    if (200 !== response.status) {
      n_proxy('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }

    users.value = json

    return users.value
  } catch (e) {
    n_proxy('error', 'Error', `Request error. ${e.message}`, e)
  } finally {
    usersLoading.value = false
  }
}

onMounted(async () => {
  supported.value = await (await request('/system/supported')).json()
  backend.value.type = supported.value[0]
})

const changeStep = async () => {
  let _

  if (stage.value <= 0) {
    // -- basic validation.
    const required = ['name', 'type', 'token']
    if (required.some(v => !backend.value[v])) {
      required.forEach(v => {
        if (!backend.value[v]) {
          notification('error', 'Error', `Please fill the required field: ${v}.`)
        }
      })
      return
    }

    if (false === /^[a-z_0-9]+$/.test(backend.value.name)) {
      notification('error', 'Error', `Backend name must be in lower case a-z, 0-9 and _ only.`)
      return
    }

    if (props.backends.find(b => b.name === backend.value.name)) {
      notification('error', 'Error', `Backend with name '${backend.value.name}' already exists.`)
      return
    }

    stage.value = 1
  }

  if (stage.value <= 1) {
    if ('plex' === backend.value.type && servers.value.length < 1) {
      _ = await getServers()
      if (servers.value.length < 1) {
        stage.value = 0
        return
      }
    }

    if (!backend.value.url) {
      return
    }

    if (false === isLimited.value && backend.value.token.includes(':')) {
      _ = await getAccessToken()
      if (!accessTokenResponse.value) {
        stage.value = 0
        return
      }
    }

    if (backend.value.token.includes(':')) {
      return
    }

    stage.value = 2
  }

  if (stage.value <= 2) {
    if (!backend.value.uuid) {
      _ = await getUUid();
      if (!backend.value.uuid) {
        stage.value = 1
        return
      }
    }

    stage.value = 3
  }

  if (stage.value <= 3) {
    if (false === isLimited.value && users.value.length < 1) {
      _ = await getUsers()
      if (users.value.length < 1) {
        stage.value = 1
        return
      }
    }

    if (!backend.value.user) {
      return
    }

    stage.value = 4
  }

  if (stage.value <= 4) {
    stage.value = 5
  }
}

const addBackend = async () => {
  const required_values = ['name', 'type', 'token', 'url', 'uuid', 'user'];

  if (required_values.some(v => !backend.value[v])) {
    required_values.forEach(v => {
      if (!backend.value[v]) {
        notification('error', 'Error', `Please fill the required field: ${v}.`)
      }
    })
    return
  }

  if ('plex' === backend.value.type) {
    let token = users.value.find(u => u.id === backend.value.user).token
    if (token && token !== backend.value.token) {
      backend.value.options.ADMIN_TOKEN = backend.value.token;
      backend.value.token = token
    }
  }

  if (isLimited.value) {
    backend.value.options.is_limited_token = true
  }

  const response = await request(`/backends/`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(backend.value)
  })

  const json = await response.json()
  if (response.status >= 400) {
    notification('error', 'Error', `Failed to Add backend. (${json.error.code}: ${json.error.message}).`)
    return false
  }

  notification('success', 'Information', `Backend ${backend.value.name} added successfully.`)

  if (true === Boolean(backup_data?.value ?? false)) {
    emit('backupData', backend)
  }

  if (true === Boolean(force_export?.value ?? false)) {
    emit('forceExport', backend)
  }

  if (true === Boolean(force_import?.value ?? false)) {
    emit('forceImport', backend)
  }

  emit('addBackend')

  return true
}

const getServers = async () => {
  if ('plex' !== backend.value.type || servers.value.length > 0) {
    return
  }

  if (!backend.value.token) {
    notification('error', 'Error', `Token is required to get list of servers.`)
    return
  }
  try {
    serversLoading.value = true

    let data = {
      name: backend.value?.name,
      token: backend.value.token,
      url: window.location.origin,
    };

    const response = await request(`/backends/discover/${backend.value.type}`, {
      method: 'POST',
      body: JSON.stringify(data)
    })

    serversLoading.value = false

    const json = await response.json()

    if (200 !== response.status) {
      n_proxy('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }

    servers.value = json

    return servers.value
  } catch (e) {
    n_proxy('error', 'Error', `Request error. ${e.message}`, e)
  } finally {
    serversLoading.value = false
  }
}

const updateIdentifier = async () => {
  backend.value.uuid = servers.value.find(s => s.uri === backend.value.url).identifier
  if (backend.value.uuid) {
    await getUsers()
  }
}

const n_proxy = (type, title, message, e = null) => {
  if ('error' === type) {
    error.value = message
  }

  if (e) {
    console.error(e)
  }

  return notification(type, title, message)
}

watch(error, v => v ? awaitElement('#backend_error', (_, e) => e.scrollIntoView({ behavior: 'smooth' })) : null)
</script>
