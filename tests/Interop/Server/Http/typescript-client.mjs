// The TypeScript SDK's client completing calls, each request going to the next of the given endpoints.
import { Client, StreamableHTTPClientTransport } from '@modelcontextprotocol/client';

const { endpoints, token, calls } = JSON.parse(process.argv[2]);

let sent = 0;
let echoedStates = 0;
let progressed = 0;

const roundRobin = async (url, init) => {
    if (typeof init?.body === 'string' && undefined !== JSON.parse(init.body).params?.requestState) {
        ++echoedStates;
    }

    const target = new URL(url);
    target.host = new URL(endpoints[sent++ % endpoints.length]).host;

    return fetch(target, init);
};

const client = new Client(
    { name: 'request-state-interop', version: '1.0.0' },
    { capabilities: { elicitation: { form: {} } }, versionNegotiation: { mode: { pin: '2026-07-28' } } },
);
client.setRequestHandler('elicitation/create', async () => ({ action: 'accept', content: { v: 'typescript' } }));

const outcome = { results: [], error: null };

try {
    await client.connect(
        new StreamableHTTPClientTransport(new URL(endpoints[0]), {
            fetch: roundRobin,
            requestInit: { headers: { Authorization: `Bearer ${token}` } },
        }),
    );

    const invoke = {
        'tools/call': (params) => client.callTool(params, { onprogress: () => ++progressed }),
        'prompts/get': (params) => client.getPrompt(params),
        'resources/read': (params) => client.readResource(params),
    };

    for (const { method, params } of calls) {
        try {
            const result = await invoke[method](params);
            outcome.results.push(result.content?.[0].text ?? result.messages?.[0].content.text ?? result.contents?.[0].text);
        } catch (error) {
            outcome.error = String(error.message ?? error);
        }
    }

    outcome.protocolVersion = client.getNegotiatedProtocolVersion?.();
} catch (error) {
    outcome.error = `connect: ${error.message ?? error}`;
}

outcome.echoedStates = echoedStates;
outcome.progressed = progressed;
process.stdout.write(JSON.stringify(outcome));
await client.close().catch(() => {});
process.exit(0);
