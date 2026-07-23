/**
 * Shared JSDoc type definitions for the Superdav AI Agent plugin.
 *
 * This file is not imported at runtime — it exists solely to provide
 * type information to editors and documentation tools via JSDoc.
 *
 * @module types
 */

/**
 * A single part of a message (text or other content).
 *
 * @typedef {Object} MessagePart
 * @property {string} [text] - Text content of this part.
 */

/**
 * Debug metadata attached to a model message when debug mode is active.
 *
 * @typedef {Object} MessageDebug
 * @property {number}                               responseTimeMs  - Total response time in milliseconds.
 * @property {{prompt: number, completion: number}} tokenUsage      - Token counts.
 * @property {number}                               tokensPerSecond - Streaming speed in tokens per second.
 * @property {string}                               modelId         - Model identifier used for this response.
 * @property {number}                               costEstimate    - Estimated cost in USD.
 * @property {number}                               iterationsUsed  - Number of agent loop iterations.
 * @property {number}                               toolCallCount   - Number of tool calls made.
 * @property {string[]}                             toolNames       - Unique tool names called.
 */

/**
 * A single chat message.
 *
 * @typedef {Object} Message
 * @property {'user'|'model'|'system'|'function'} role        - Message role.
 * @property {MessagePart[]}                      [parts]     - Message content parts.
 * @property {Array}                              [notice]    - Optional UI notice metadata for system messages.
 * @property {ToolCall[]}                         [toolCalls] - Tool calls attached to this message.
 * @property {MessageDebug}                       [debug]     - Debug metadata (debug mode only).
 */

/**
 * A tool call or tool result entry.
 *
 * @typedef {Object} ToolCall
 * @property {'call'|'result'} type       - Whether this is a call or its result.
 * @property {string}          name       - Tool name.
 * @property {Object}          [args]     - Arguments passed to the tool (for calls).
 * @property {string|Object}   [response] - Response from the tool (for results).
 * @property {string}          [id]       - Tool call identifier.
 */

/**
 * A model entry within a provider.
 *
 * @typedef {Object} ProviderModel
 * @property {string} id   - Model identifier.
 * @property {string} name - Human-readable model name.
 */

/**
 * A scrubbed model-discovery failure state.
 *
 * @typedef {Object} ModelDiscoveryFailure
 * @property {'retryable_unavailable'|'unavailable'} state     Current discovery state.
 * @property {string}                                code      Normalized failure code.
 * @property {boolean}                               retryable Whether refresh may recover.
 * @property {number}                                attempts  Model listing attempts.
 * @property {number}                                [status]  Safe HTTP status when available.
 */

/**
 * An AI provider configuration.
 *
 * @typedef {Object} Provider
 * @property {string}                id                - Provider identifier.
 * @property {string}                name              - Human-readable provider name.
 * @property {string}                [default_model]   - Provider-preferred default model ID.
 * @property {ProviderModel[]}       [models]          - Available models for this provider.
 * @property {Object}                [status]          - Provider-specific safe status metadata.
 * @property {ModelDiscoveryFailure} [model_discovery] - Safe discovery failure metadata.
 */

/**
 * A chat session summary (as returned by the sessions list endpoint).
 *
 * @typedef {Object} Session
 * @property {number|string}                        id            - Session identifier.
 * @property {string}                               [title]       - Session title.
 * @property {string}                               [status]      - Session status: 'active', 'archived', 'trash'.
 * @property {string|number}                        [pinned]      - Non-zero/truthy when pinned.
 * @property {string}                               [folder]      - Folder name the session belongs to.
 * @property {string}                               [updated_at]  - ISO 8601 timestamp (without trailing Z).
 * @property {string}                               [provider_id] - Provider used for this session.
 * @property {string}                               [model_id]    - Model used for this session.
 * @property {{prompt: number, completion: number}} [token_usage] - Cumulative token usage.
 */

/**
 * A memory entry.
 *
 * @typedef {Object} Memory
 * @property {number} id       - Memory identifier.
 * @property {string} category - Memory category (e.g. 'general').
 * @property {string} content  - Memory content text.
 */

/**
 * A skill entry.
 *
 * @typedef {Object} Skill
 * @property {number}  id              - Skill identifier.
 * @property {string}  slug            - URL-safe unique identifier.
 * @property {string}  name            - Human-readable skill name.
 * @property {string}  [label]         - Deprecated alias for name.
 * @property {string}  [description]   - One-line description shown in the skill index.
 * @property {string}  [content]       - Full skill guide markdown content.
 * @property {boolean} [is_builtin]    - Whether this is a framework-bundled skill.
 * @property {boolean} [enabled]       - Whether the skill is active.
 * @property {string}  [version]       - Semver string of the current skill content.
 * @property {string}  [source_url]    - Remote URL the skill originates from (empty for user-created).
 * @property {boolean} [user_modified] - True when an admin has edited a built-in skill (blocks auto-updates).
 * @property {number}  [word_count]    - Word count of the skill content.
 * @property {string}  [created_at]    - MySQL UTC datetime of creation.
 * @property {string}  [updated_at]    - MySQL UTC datetime of last update.
 */

/**
 * Plugin settings object.
 *
 * @typedef {Object} Settings
 * @property {boolean} [onboarding_complete]    - Whether onboarding has been completed.
 * @property {string}  [default_provider]       - Default provider ID.
 * @property {string}  [default_model]          - Default model ID.
 * @property {number}  [context_window_default] - Default context window size in tokens.
 */

/**
 * Token usage counters.
 *
 * @typedef {Object} TokenUsage
 * @property {number} prompt     - Input/prompt tokens consumed.
 * @property {number} completion - Output/completion tokens generated.
 */

/**
 * Pending tool confirmation payload.
 *
 * @typedef {Object} PendingConfirmation
 * @property {string}     jobId - Job identifier awaiting confirmation.
 * @property {ToolCall[]} tools - Tools pending user approval.
 */

/**
 * The Redux store state shape.
 *
 * @typedef {Object} StoreState
 * @property {Provider[]}                                                providers               - Available AI providers.
 * @property {boolean}                                                   providersLoaded         - Whether providers have been fetched.
 * @property {boolean}                                                   providersLoading        - Whether a providers fetch is in-flight.
 * @property {Session[]}                                                 sessions                - Session list.
 * @property {boolean}                                                   sessionsLoaded          - Whether sessions have been fetched.
 * @property {number|null}                                               currentSessionId        - Active session ID.
 * @property {Message[]}                                                 currentSessionMessages  - Messages in the active session.
 * @property {ToolCall[]}                                                currentSessionToolCalls - Tool calls in the active session.
 * @property {boolean}                                                   sending                 - Whether a message is in-flight.
 * @property {boolean}                                                   streamError             - Whether the last send failed.
 * @property {number|null}                                               streamErrorSessionId    - Session ID associated with the current send error.
 * @property {string}                                                    lastUserMessage         - Last user message text for retry fallback.
 * @property {string|null}                                               currentJobId            - Active polling job ID.
 * @property {string}                                                    selectedProviderId      - Currently selected provider ID.
 * @property {string}                                                    selectedModelId         - Currently selected model ID.
 * @property {'admin'|'frontend'|'customer_simple'|'public_docs'|string} [chatUiMode]            - Active chat UI mode.
 * @property {boolean}                                                   floatingOpen            - Whether the floating panel is open.
 * @property {boolean}                                                   floatingMinimized       - Whether the floating panel is minimized.
 * @property {string|Object}                                             pageContext             - Structured page context for the AI.
 * @property {string}                                                    sessionFilter           - Active session filter tab.
 * @property {string}                                                    sessionFolder           - Active folder filter.
 * @property {string}                                                    sessionSearch           - Active search query.
 * @property {string[]}                                                  folders                 - Available folder names.
 * @property {boolean}                                                   foldersLoaded           - Whether folders have been fetched.
 * @property {Settings|null}                                             settings                - Plugin settings.
 * @property {boolean}                                                   settingsLoaded          - Whether settings have been fetched.
 * @property {boolean}                                                   settingsLoading         - Whether a settings fetch is in-flight.
 * @property {Memory[]}                                                  memories                - Memory entries.
 * @property {boolean}                                                   memoriesLoaded          - Whether memories have been fetched.
 * @property {Skill[]}                                                   skills                  - Skill entries.
 * @property {boolean}                                                   skillsLoaded            - Whether skills have been fetched.
 * @property {Object}                                                    skillStats              - Aggregated usage stats indexed by skill_id.
 * @property {boolean}                                                   skillStatsLoaded        - Whether skill stats have been fetched.
 * @property {Object}                                                    skillUpdates            - Update availability map from check-updates, indexed by skill_id.
 * @property {boolean}                                                   skillUpdatesChecking    - Whether a check-updates request is in flight.
 * @property {TokenUsage}                                                tokenUsage              - Cumulative token usage for the session.
 * @property {PendingConfirmation|null}                                  pendingConfirmation     - Pending tool confirmation.
 * @property {number}                                                    sendTimestamp           - Timestamp of the last send (ms since epoch).
 */

/**
 * A slash command definition.
 *
 * @typedef {Object} SlashCommand
 * @property {string} name        - Command name including leading slash (e.g. '/new').
 * @property {string} description - Human-readable description.
 * @property {string} action      - Action key used in the handler switch.
 */

/**
 * A keyboard shortcut definition.
 *
 * @typedef {Object} KeyboardShortcut
 * @property {string} combo - Key combination string (e.g. 'mod+k', 'escape').
 * @property {string} label - Human-readable label for the shortcut.
 */

/**
 * A 2D position (pixels from top-left of viewport).
 *
 * @typedef {Object} Position
 * @property {number} x - Horizontal offset in pixels.
 * @property {number} y - Vertical offset in pixels.
 */

/**
 * Return value of the useDrag hook.
 *
 * @typedef {Object} UseDragReturn
 * @property {Position|null} position        - Current custom position, or null for CSS default.
 * @property {boolean}       isDragging      - Whether a drag is in progress.
 * @property {Function}      handleMouseDown - mousedown event handler to attach to the drag handle.
 * @property {Function}      resetPosition   - Resets position to CSS default and clears storage.
 */
