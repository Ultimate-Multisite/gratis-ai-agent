# Issue #1497 Fix: Agent Over-Reports Completion

## Problem
The AI agent was claiming to have completed site configuration tasks (setting front page, site title, creating menus) without actually calling the corresponding tools. This resulted in misleading completion messages that didn't match the actual WordPress state.

## Root Cause
The system prompt did not explicitly instruct the agent to:
1. Call `sd-ai-agent/update-option` to set `blogname` (site title)
2. Call `sd-ai-agent/update-option` with `show_on_front` and `page_on_front` to set a static front page
3. Call `sd-ai-agent/create-menu`, `sd-ai-agent/add-menu-item`, and `sd-ai-agent/assign-menu-location` to create and assign navigation menus

Additionally, the agent was not instructed to only claim completion for work it actually performed.

## Solution

### 1. Enhanced System Prompt (includes/Core/SystemInstructionBuilder.php)

Added two key improvements:

#### a) Core Principle #6: Honesty
```
6. **Only claim completion for work you actually performed.** Do not claim to have set the site title, front page, or created menus unless you have actually called the corresponding tools and received success responses.
```

#### b) New "Site Configuration" Section
Added explicit step-by-step instructions for:
- **Setting site title**: Use `sd-ai-agent/update-option` with `option_name="blogname"`
- **Setting static front page**: Create a page, then use `sd-ai-agent/update-option` twice with `show_on_front="page"` and `page_on_front=<post_id>`
- **Creating and assigning menus**: Use `sd-ai-agent/create-menu`, `sd-ai-agent/add-menu-item`, and `sd-ai-agent/assign-menu-location`

### 2. Test Coverage (tests/SdAiAgent/Core/SystemInstructionBuilderTest.php)

Added comprehensive tests to verify:
- The system instruction includes the Site Configuration section
- All required ability names are mentioned (`update-option`, `create-menu`, `add-menu-item`, `assign-menu-location`)
- All required option names are mentioned (`blogname`, `show_on_front`, `page_on_front`)
- The honesty principle is included

## Verification

The fix addresses both layers of the issue:

1. **Behaviour**: The agent now has explicit, step-by-step instructions for performing these tasks
2. **Honesty**: The agent is explicitly instructed to only claim completion for work it actually performed

When the agent receives a "build me a website" prompt, it will now:
1. Create the pages (already working)
2. Call the tools to set the site title, front page, and menus (now explicitly instructed)
3. Only claim completion for work it actually performed (now explicitly instructed)

## Related Abilities

The following abilities were already available and are now explicitly referenced in the system prompt:
- `sd-ai-agent/update-option` - Set WordPress options
- `sd-ai-agent/create-menu` - Create navigation menus
- `sd-ai-agent/add-menu-item` - Add items to menus
- `sd-ai-agent/assign-menu-location` - Assign menus to theme locations

## Files Modified

1. `includes/Core/SystemInstructionBuilder.php` - Enhanced system prompt
2. `tests/SdAiAgent/Core/SystemInstructionBuilderTest.php` - New test file

## Testing

Run the test suite to verify:
```bash
npm run test:php -- --filter SystemInstructionBuilderTest
```

Or manually test by:
1. Provisioning a fresh demo site
2. Sending a "Build me a website" prompt
3. Verifying that the agent calls the tools for site title, front page, and menus
4. Verifying that the completion message only claims work that was actually performed
