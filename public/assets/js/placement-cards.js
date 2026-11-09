// Карточки теста на уровень: один вопрос на экране вместо простыни из тридцати.
// Скрипт только улучшает готовую форму: все вопросы и radio уже в разметке, поэтому
// без JavaScript видны сразу все, а с ним лишние карточки скрываются и открываются
// по одной. Ответ на вопрос сам открывает следующую карточку; «Назад» возвращает
// к предыдущей, «Завершить тест» появляется на последней и отправляет ту же форму.
(() => {
  'use strict';

  const form = document.querySelector('[data-placement-cards]');
  if (!form) {
    return;
  }

  const cards = Array.from(form.querySelectorAll('[data-placement-card]'));
  if (cards.length === 0) {
    return;
  }

  const progress = form.querySelector('[data-placement-progress]');
  const progressLabel = form.querySelector('[data-placement-progress-label]');
  const progressPercent = form.querySelector('[data-placement-progress-percent]');
  const progressBar = form.querySelector('[data-placement-progress-bar]');
  const previousButton = form.querySelector('[data-placement-prev]');
  const nextButton = form.querySelector('[data-placement-next]');
  const submitButton = form.querySelector('[data-placement-submit]');
  const error = form.querySelector('[data-placement-error]');

  let current = 0;

  const isAnswered = (card) => card.querySelector('input[type="radio"]:checked') !== null;

  const showError = (card) => {
    if (error) {
      error.hidden = false;
    }
    const first = card.querySelector('input[type="radio"]');
    if (first) {
      first.focus({ preventScroll: true });
    }
  };

  const hideError = () => {
    if (error) {
      error.hidden = true;
    }
  };

  const show = (index, focusInput = true) => {
    if (index < 0 || index >= cards.length) {
      return;
    }

    current = index;

    cards.forEach((card, cardIndex) => {
      card.hidden = cardIndex !== current;
    });

    if (progress) {
      progress.hidden = false;
    }
    if (progressLabel) {
      progressLabel.textContent = 'Вопрос ' + String(current + 1) + ' из ' + String(cards.length);
    }

    const percent = Math.round(((current + 1) / cards.length) * 100);
    if (progressPercent) {
      progressPercent.textContent = String(percent) + '%';
    }
    if (progressBar) {
      progressBar.style.width = String(percent) + '%';
    }

    if (previousButton) {
      previousButton.hidden = current === 0;
    }
    if (nextButton) {
      nextButton.hidden = current === cards.length - 1;
    }
    if (submitButton) {
      submitButton.hidden = current !== cards.length - 1;
    }

    hideError();

    if (focusInput) {
      const first = cards[current].querySelector('input[type="radio"]');
      if (first) {
        first.focus({ preventScroll: true });
      }
      cards[current].scrollIntoView({ block: 'nearest' });
    }
  };

  const advance = () => {
    if (current >= cards.length - 1) {
      return;
    }
    show(current + 1);
  };

  // Выбор ответа открывает следующую карточку. Небольшая задержка нужна, чтобы
  // отмеченный вариант успел отрисоваться и пользователь увидел свой выбор.
  form.addEventListener('change', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || input.type !== 'radio') {
      return;
    }

    const card = input.closest('[data-placement-card]');
    if (!card) {
      return;
    }

    window.setTimeout(() => {
      // Если пользователь уже ушёл с этой карточки кнопкой, ничего не двигаем.
      if (card === cards[current]) {
        advance();
      }
    }, 180);
  });

  if (nextButton) {
    nextButton.addEventListener('click', () => {
      const card = cards[current];
      if (!isAnswered(card)) {
        showError(card);
        return;
      }
      advance();
    });
  }

  if (previousButton) {
    previousButton.addEventListener('click', () => {
      show(current - 1);
    });
  }

  // Enter на вопросе не должен отправлять форму раньше последней карточки:
  // он работает как «Далее», а на последней карточке отправляет тест.
  form.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') {
      return;
    }
    const target = event.target;
    if (target instanceof HTMLElement && target.tagName === 'BUTTON' && target.type === 'submit') {
      return;
    }
    if (current === cards.length - 1) {
      return;
    }

    event.preventDefault();
    if (isAnswered(cards[current])) {
      advance();
    } else {
      showError(cards[current]);
    }
  });

  show(0, false);
})();
