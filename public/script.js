// ============================================================
// Sistema de Reserva de Hotel
// ============================================================

const API = 'api.php';

// Detecta qual página está ativa e chama a função certa
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('mapa-quartos')) {
        iniciarPaginaMapa();
    }
    if (document.getElementById('form-reserva')) {
        iniciarPaginaReservas();
    }
    if (document.getElementById('form-quarto')) {
        iniciarPaginaQuartos();
    }
});

// ---------------- Funções auxiliares ----------------

async function chamarApi(acao, metodo = 'GET', corpo = null) {
    const opcoes = { method: metodo };
    if (corpo) {
        opcoes.headers = { 'Content-Type': 'application/json' };
        opcoes.body = JSON.stringify(corpo);
    }
    const resposta = await fetch(`${API}?acao=${acao}`, opcoes);
    const dados = await resposta.json();
    if (!resposta.ok) {
        throw new Error(dados.erro || 'Erro desconhecido');
    }
    return dados;
}

function formatarData(dataISO) {
    if (!dataISO) return '';
    const [ano, mes, dia] = dataISO.split('-');
    return `${dia}/${mes}/${ano}`;
}

// Evita que texto digitado pelo usuário seja interpretado como HTML
// quando a tabela é montada com innerHTML.
function escaparHtml(texto) {
    return String(texto ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// Mostra a mensagem de erro/sucesso no topo da página em vez de alert()
function mostrarAviso(mensagem, tipo = 'erro') {
    let caixa = document.getElementById('caixa-aviso');
    if (!caixa) {
        caixa = document.createElement('div');
        caixa.id = 'caixa-aviso';
        document.querySelector('main.container').prepend(caixa);
    }
    caixa.className = `aviso aviso-${tipo}`;
    caixa.textContent = mensagem;
    caixa.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    clearTimeout(caixa.temporizador);
    caixa.temporizador = setTimeout(() => caixa.remove(), 5000);
}

// ---------------- Validações no navegador ----------------
// O back-end valida tudo de novo (nunca confiar só no cliente),
// mas validar aqui evita ida e volta desnecessária ao servidor.

function validarFormularioReserva(dados) {
    if (dados.cliente_nome.trim().length < 3) {
        return 'O nome do cliente deve ter pelo menos 3 caracteres.';
    }

    const digitos = dados.cliente_telefone.replace(/\D/g, '');
    if (digitos.length < 10 || digitos.length > 11) {
        return 'Telefone inválido. Informe DDD + número (10 ou 11 dígitos).';
    }

    if (!dados.data_entrada || !dados.data_saida) {
        return 'Preencha as datas de check-in e check-out.';
    }

    if (dados.data_saida <= dados.data_entrada) {
        return 'A data de check-out precisa ser posterior à data de check-in.';
    }

    return null; // sem erro
}

function validarFormularioQuarto(dados) {
    if (dados.numero.trim() === '') {
        return 'Informe o número do quarto.';
    }
    const capacidade = Number(dados.capacidade);
    if (!Number.isInteger(capacidade) || capacidade < 1 || capacidade > 10) {
        return 'A capacidade deve ser um número inteiro entre 1 e 10.';
    }
    return null;
}

// ================= PÁGINA: MAPA DE QUARTOS =================

async function iniciarPaginaMapa() {
    await carregarMapaQuartos();

    document.getElementById('btn-cancelar-modal').addEventListener('click', () => {
        document.getElementById('modal-status').classList.add('escondido');
    });

    document.getElementById('form-status').addEventListener('submit', async (evento) => {
        evento.preventDefault();
        const quartoId = document.getElementById('modal-quarto-id').value;
        const status = document.getElementById('modal-status-select').value;
        try {
            await chamarApi('atualizar_status_quarto', 'POST', { quarto_id: quartoId, status });
            document.getElementById('modal-status').classList.add('escondido');
            await carregarMapaQuartos();
            mostrarAviso('Status do quarto atualizado.', 'sucesso');
        } catch (erro) {
            mostrarAviso('Erro ao atualizar status: ' + erro.message);
        }
    });
}

async function carregarMapaQuartos() {
    const container = document.getElementById('mapa-quartos');
    try {
        const quartos = await chamarApi('listar_quartos');
        container.innerHTML = '';

        if (quartos.length === 0) {
            container.innerHTML = '<p>Nenhum quarto cadastrado. Cadastre em "Gerenciar Quartos".</p>';
            return;
        }

        const rotulos = { disponivel: 'Disponível', agendado: 'Agendado', ocupado: 'Ocupado' };

        quartos.forEach((quarto) => {
            const card = document.createElement('div');
            card.className = `quarto-card ${quarto.status}`;
            card.innerHTML = `
                <div class="quarto-numero">${escaparHtml(quarto.numero)}</div>
                <div class="quarto-tipo">${escaparHtml(quarto.tipo)} · até ${escaparHtml(quarto.capacidade)} pessoas</div>
                <span class="quarto-status ${quarto.status}">${rotulos[quarto.status]}</span>
            `;
            card.addEventListener('click', () => abrirModalStatus(quarto));
            container.appendChild(card);
        });
    } catch (erro) {
        container.innerHTML = `<p>Erro ao carregar quartos: ${escaparHtml(erro.message)}</p>`;
    }
}

function abrirModalStatus(quarto) {
    document.getElementById('modal-quarto-id').value = quarto.id;
    document.getElementById('modal-status-select').value = quarto.status;
    document.getElementById('modal-titulo').textContent = `Quarto ${quarto.numero} - Alterar status`;
    document.getElementById('modal-status').classList.remove('escondido');
}

// ================= PÁGINA: RESERVAS (CRUD) =================

let listaReservasAtual = []; // guarda a última lista carregada, para filtrar sem buscar de novo

async function iniciarPaginaReservas() {
    await preencherSelectQuartos();
    await carregarTabelaReservas();

    document.getElementById('form-reserva').addEventListener('submit', salvarReserva);
    document.getElementById('btn-cancelar-edicao').addEventListener('click', limparFormularioReserva);

    // Quando o check-in muda, o check-out não pode ser anterior a ele.
    // O atributo "min" faz o próprio navegador bloquear a escolha inválida.
    document.getElementById('data_entrada').addEventListener('change', (evento) => {
        document.getElementById('data_saida').min = evento.target.value;
    });

    document.getElementById('busca-reserva').addEventListener('input', (evento) => {
        const termo = evento.target.value.trim().toLowerCase();

        if (!termo) {
            renderizarTabelaReservas(listaReservasAtual);
            return;
        }

        const filtradas = listaReservasAtual.filter((r) => {
            const nome = (r.cliente_nome || '').toLowerCase();
            const telefone = (r.cliente_telefone || '').toLowerCase();
            return nome.includes(termo) || telefone.includes(termo);
        });

        renderizarTabelaReservas(filtradas);
    });
}

async function preencherSelectQuartos() {
    const select = document.getElementById('quarto_id');
    const quartos = await chamarApi('listar_quartos');
    select.innerHTML = quartos
        .map((q) => `<option value="${q.id}">${escaparHtml(q.numero)} - ${escaparHtml(q.tipo)}</option>`)
        .join('');
}

async function carregarTabelaReservas() {
    const corpoTabela = document.querySelector('#tabela-reservas tbody');
    try {
        listaReservasAtual = await chamarApi('listar_reservas');
        renderizarTabelaReservas(listaReservasAtual);
    } catch (erro) {
        corpoTabela.innerHTML = `<tr><td colspan="7">Erro ao carregar reservas: ${escaparHtml(erro.message)}</td></tr>`;
    }
}

function renderizarTabelaReservas(reservas) {
    const corpoTabela = document.querySelector('#tabela-reservas tbody');

    if (reservas.length === 0) {
        corpoTabela.innerHTML = '<tr><td colspan="7">Nenhuma reserva encontrada.</td></tr>';
        return;
    }

    corpoTabela.innerHTML = reservas.map((r) => `
        <tr>
            <td>${escaparHtml(r.quarto_numero)}</td>
            <td>${escaparHtml(r.cliente_nome)}</td>
            <td>${escaparHtml(r.cliente_telefone)}</td>
            <td>${formatarData(r.data_entrada)}</td>
            <td>${formatarData(r.data_saida)}</td>
            <td><span class="tag-status ${r.status}">${r.status}</span></td>
            <td class="acoes-tabela">
                <button class="editar" data-id="${r.id}">Editar</button>
                <button class="excluir" data-id="${r.id}">Excluir</button>
            </td>
        </tr>
    `).join('');

    corpoTabela.querySelectorAll('.editar').forEach((botao) => {
        botao.addEventListener('click', () => editarReserva(reservas, botao.dataset.id));
    });
    corpoTabela.querySelectorAll('.excluir').forEach((botao) => {
        botao.addEventListener('click', () => excluirReserva(botao.dataset.id));
    });
}

async function salvarReserva(evento) {
    evento.preventDefault();

    const id = document.getElementById('reserva-id').value;
    const dados = {
        quarto_id: document.getElementById('quarto_id').value,
        cliente_nome: document.getElementById('cliente_nome').value,
        cliente_telefone: document.getElementById('cliente_telefone').value,
        data_entrada: document.getElementById('data_entrada').value,
        data_saida: document.getElementById('data_saida').value,
        status: document.getElementById('status').value,
        observacoes: document.getElementById('observacoes').value,
    };

    const erroValidacao = validarFormularioReserva(dados);
    if (erroValidacao) {
        mostrarAviso(erroValidacao);
        return;
    }

    const botao = document.getElementById('btn-salvar');
    botao.disabled = true; // evita duplo clique criando duas reservas

    try {
        if (id) {
            dados.id = id;
            await chamarApi('atualizar_reserva', 'POST', dados);
            mostrarAviso('Reserva atualizada com sucesso.', 'sucesso');
        } else {
            await chamarApi('criar_reserva', 'POST', dados);
            mostrarAviso('Reserva cadastrada com sucesso.', 'sucesso');
        }
        limparFormularioReserva();
        await carregarTabelaReservas();
    } catch (erro) {
        mostrarAviso(erro.message);
    } finally {
        botao.disabled = false;
    }
}

function editarReserva(reservas, id) {
    const reserva = reservas.find((r) => String(r.id) === String(id));
    if (!reserva) return;

    document.getElementById('reserva-id').value = reserva.id;
    document.getElementById('quarto_id').value = reserva.quarto_id;
    document.getElementById('cliente_nome').value = reserva.cliente_nome;
    document.getElementById('cliente_telefone').value = reserva.cliente_telefone;
    document.getElementById('data_entrada').value = reserva.data_entrada;
    document.getElementById('data_saida').value = reserva.data_saida;
    document.getElementById('data_saida').min = reserva.data_entrada;
    document.getElementById('status').value = reserva.status;
    document.getElementById('observacoes').value = reserva.observacoes || '';

    document.getElementById('form-titulo').textContent = 'Editar Reserva';
    document.getElementById('btn-salvar').textContent = 'Atualizar Reserva';
    document.getElementById('btn-cancelar-edicao').classList.remove('escondido');

    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function excluirReserva(id) {
    if (!confirm('Tem certeza que deseja excluir esta reserva?')) return;
    try {
        await chamarApi('excluir_reserva', 'POST', { id });
        await carregarTabelaReservas();
        mostrarAviso('Reserva excluída.', 'sucesso');
    } catch (erro) {
        mostrarAviso('Erro ao excluir reserva: ' + erro.message);
    }
}

function limparFormularioReserva() {
    document.getElementById('form-reserva').reset();
    document.getElementById('reserva-id').value = '';
    document.getElementById('data_saida').removeAttribute('min');
    document.getElementById('form-titulo').textContent = 'Nova Reserva';
    document.getElementById('btn-salvar').textContent = 'Salvar Reserva';
    document.getElementById('btn-cancelar-edicao').classList.add('escondido');
}

// ================= PÁGINA: QUARTOS (CRUD) =================

let listaQuartosAtual = [];

async function iniciarPaginaQuartos() {
    await carregarTabelaQuartos();

    document.getElementById('form-quarto').addEventListener('submit', salvarQuarto);
    document.getElementById('btn-cancelar-edicao-quarto').addEventListener('click', limparFormularioQuarto);
}

async function carregarTabelaQuartos() {
    const corpoTabela = document.querySelector('#tabela-quartos tbody');
    try {
        listaQuartosAtual = await chamarApi('listar_quartos');
        renderizarTabelaQuartos(listaQuartosAtual);
    } catch (erro) {
        corpoTabela.innerHTML = `<tr><td colspan="6">Erro ao carregar quartos: ${escaparHtml(erro.message)}</td></tr>`;
    }
}

function renderizarTabelaQuartos(quartos) {
    const corpoTabela = document.querySelector('#tabela-quartos tbody');

    if (quartos.length === 0) {
        corpoTabela.innerHTML = '<tr><td colspan="6">Nenhum quarto cadastrado.</td></tr>';
        return;
    }

    const rotulos = { disponivel: 'Disponível', agendado: 'Agendado', ocupado: 'Ocupado' };

    corpoTabela.innerHTML = quartos.map((q) => `
        <tr>
            <td>${escaparHtml(q.numero)}</td>
            <td>${escaparHtml(q.tipo)}</td>
            <td>${escaparHtml(q.capacidade)} pessoas</td>
            <td><span class="tag-status ${q.status}">${rotulos[q.status]}</span></td>
            <td>${escaparHtml(q.reservas_ativas)}</td>
            <td class="acoes-tabela">
                <button class="editar" data-id="${q.id}">Editar</button>
                <button class="excluir" data-id="${q.id}">Excluir</button>
            </td>
        </tr>
    `).join('');

    corpoTabela.querySelectorAll('.editar').forEach((botao) => {
        botao.addEventListener('click', () => editarQuarto(quartos, botao.dataset.id));
    });
    corpoTabela.querySelectorAll('.excluir').forEach((botao) => {
        botao.addEventListener('click', () => excluirQuarto(botao.dataset.id));
    });
}

async function salvarQuarto(evento) {
    evento.preventDefault();

    const id = document.getElementById('quarto-id').value;
    const dados = {
        numero: document.getElementById('quarto-numero').value,
        tipo: document.getElementById('quarto-tipo').value,
        capacidade: document.getElementById('quarto-capacidade').value,
    };

    const erroValidacao = validarFormularioQuarto(dados);
    if (erroValidacao) {
        mostrarAviso(erroValidacao);
        return;
    }

    const botao = document.getElementById('btn-salvar-quarto');
    botao.disabled = true;

    try {
        if (id) {
            dados.id = id;
            await chamarApi('atualizar_quarto', 'POST', dados);
            mostrarAviso('Quarto atualizado com sucesso.', 'sucesso');
        } else {
            await chamarApi('criar_quarto', 'POST', dados);
            mostrarAviso('Quarto cadastrado com sucesso.', 'sucesso');
        }
        limparFormularioQuarto();
        await carregarTabelaQuartos();
    } catch (erro) {
        mostrarAviso(erro.message);
    } finally {
        botao.disabled = false;
    }
}

function editarQuarto(quartos, id) {
    const quarto = quartos.find((q) => String(q.id) === String(id));
    if (!quarto) return;

    document.getElementById('quarto-id').value = quarto.id;
    document.getElementById('quarto-numero').value = quarto.numero;
    document.getElementById('quarto-tipo').value = quarto.tipo;
    document.getElementById('quarto-capacidade').value = quarto.capacidade;

    document.getElementById('form-quarto-titulo').textContent = 'Editar Quarto';
    document.getElementById('btn-salvar-quarto').textContent = 'Atualizar Quarto';
    document.getElementById('btn-cancelar-edicao-quarto').classList.remove('escondido');

    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function excluirQuarto(id) {
    const quarto = listaQuartosAtual.find((q) => String(q.id) === String(id));

    // Aviso antecipado: o back-end também bloqueia, mas é melhor
    // explicar o motivo antes de o usuário tentar.
    if (quarto && Number(quarto.reservas_ativas) > 0) {
        mostrarAviso(`O quarto ${quarto.numero} tem ${quarto.reservas_ativas} reserva(s) ativa(s) e não pode ser excluído.`);
        return;
    }

    if (!confirm('Tem certeza que deseja excluir este quarto?')) return;

    try {
        await chamarApi('excluir_quarto', 'POST', { id });
        await carregarTabelaQuartos();
        mostrarAviso('Quarto excluído.', 'sucesso');
    } catch (erro) {
        mostrarAviso(erro.message);
    }
}

function limparFormularioQuarto() {
    document.getElementById('form-quarto').reset();
    document.getElementById('quarto-id').value = '';
    document.getElementById('form-quarto-titulo').textContent = 'Novo Quarto';
    document.getElementById('btn-salvar-quarto').textContent = 'Salvar Quarto';
    document.getElementById('btn-cancelar-edicao-quarto').classList.add('escondido');
}
